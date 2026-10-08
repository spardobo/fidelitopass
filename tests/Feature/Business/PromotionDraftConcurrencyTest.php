<?php

use App\Actions\Promotions\SavePromotionDraft;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('serializes competing aggregate updates in Business then Promotion lock order', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('testing');

    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $promotion = app(SavePromotionDraft::class)->handle($owner, concurrentDraftInput(
        'Initial aggregate',
        8,
        [concurrentDraftWindow(1, '08:00', '10:00', 2)],
    ));

    // Commit only these test fixtures so independent PostgreSQL sessions can see them.
    DB::commit();

    $connection = DB::connection();
    $parentBackendId = (int) $connection->scalar('SELECT pg_backend_pid()');
    $connection->beginTransaction();
    $connection->table('promotions')->where('id', $promotion->id)->lockForUpdate()->first();

    $versions = [
        concurrentDraftInput('Concurrent aggregate A', 11, [
            concurrentDraftWindow(1, '09:00', '11:00', 2),
            concurrentDraftWindow(3, null, null, 5),
        ]),
        concurrentDraftInput('Concurrent aggregate B', 13, [
            concurrentDraftWindow(2, '13:00', '15:00', 3),
            concurrentDraftWindow(4, null, null, 2),
        ]),
    ];
    $processes = [];
    $lockHeld = true;

    try {
        foreach ($versions as $version) {
            $payload = base64_encode(json_encode([
                'actor_id' => $owner->id,
                'promotion_id' => $promotion->id,
                'input' => $version,
            ], JSON_THROW_ON_ERROR));

            $workerCode = <<<'PHP'
                require getcwd().'/vendor/autoload.php';
                $app = require getcwd().'/bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                $payload = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
                if (DB::connection()->getDatabaseName() !== 'testing') {
                    fwrite(STDERR, "Refusing to use a non-testing database.\n");
                    exit(2);
                }
                fwrite(STDOUT, json_encode(['backend_id' => (int) DB::scalar('SELECT pg_backend_pid()')]).PHP_EOL);
                fflush(STDOUT);
                $actor = App\Models\User::query()->findOrFail($payload['actor_id']);
                $promotion = App\Models\Promotion::query()->findOrFail($payload['promotion_id']);
                $updated = app(App\Actions\Promotions\SavePromotionDraft::class)->handle($actor, $payload['input'], $promotion);
                echo json_encode(['saved' => true, 'reward_title' => $updated->reward_title], JSON_THROW_ON_ERROR).PHP_EOL;
                PHP;

            $process = new Process(
                [PHP_BINARY, '-r', $workerCode, $payload],
                base_path(),
                [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'pgsql',
                    'DB_DATABASE' => 'testing',
                    'DB_URL' => '',
                    'DB_HOST' => config('database.connections.pgsql.host'),
                    'DB_PORT' => (string) config('database.connections.pgsql.port'),
                    'DB_USERNAME' => config('database.connections.pgsql.username'),
                    'DB_PASSWORD' => config('database.connections.pgsql.password'),
                ],
                null,
                12,
            );
            $process->start();
            $processes[] = ['process' => $process];
        }

        $waitingWorkers = [];
        $backendIds = [];
        $activityDiagnostics = [];
        $deadline = hrtime(true) + 5_000_000_000;

        do {
            $waitingWorkers = [];
            $activityDiagnostics = [];

            // Refresh pg_stat_activity's transaction-scoped snapshot before each polling round.
            DB::select('SELECT pg_stat_clear_snapshot()');

            foreach ($processes as $index => $worker) {
                $process = $worker['process'];
                $output = $process->getOutput();

                foreach (explode(PHP_EOL, $output) as $line) {
                    $message = json_decode($line, true);

                    if (is_array($message) && isset($message['backend_id'])) {
                        $backendIds[$index] = (int) $message['backend_id'];
                    }
                }

                if (! isset($backendIds[$index])) {
                    $activityDiagnostics[$index] = [
                        'backend_id' => null,
                        'row_present' => false,
                        'wait_event_type' => null,
                        'wait_event' => null,
                        'blockers' => [],
                        'worker_running' => $process->isRunning(),
                        'worker_exit_code' => $process->getExitCode(),
                        'worker_stderr_tail' => substr($process->getErrorOutput(), -500),
                    ];

                    continue;
                }

                $activity = DB::selectOne(
                    'SELECT wait_event_type, wait_event, pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE pid = ?',
                    [$backendIds[$index]],
                );
                $blockers = $activity === null
                    ? []
                    : array_map(
                        'intval',
                        array_filter(explode(',', trim($activity->blockers, '{}'))),
                    );
                $activityDiagnostics[$index] = [
                    'backend_id' => $backendIds[$index],
                    'row_present' => $activity !== null,
                    'wait_event_type' => $activity?->wait_event_type,
                    'wait_event' => $activity?->wait_event,
                    'blockers' => $blockers,
                    'worker_running' => $process->isRunning(),
                    'worker_exit_code' => $process->getExitCode(),
                    'worker_stderr_tail' => substr($process->getErrorOutput(), -500),
                ];

                if ($activity?->wait_event_type === 'Lock') {
                    $waitingWorkers[] = [
                        'backend_id' => $backendIds[$index],
                        'blockers' => $blockers,
                    ];
                }
            }

            if (count($waitingWorkers) === 2) {
                break;
            }

            if (collect($processes)->contains(fn (array $worker): bool => ! $worker['process']->isRunning())) {
                break;
            }

            usleep(10_000);
        } while (hrtime(true) < $deadline);

        $connection->commit();
        $lockHeld = false;

        foreach ($processes as $worker) {
            $worker['process']->wait();
        }

        $parentBlockedCount = collect($waitingWorkers)
            ->filter(fn (array $worker): bool => in_array($parentBackendId, $worker['blockers'], true))
            ->count();
        $workerIds = array_column($waitingWorkers, 'backend_id');
        $workerBlockedCount = collect($waitingWorkers)
            ->filter(fn (array $worker): bool => count(array_intersect(
                $worker['blockers'],
                array_diff($workerIds, [$worker['backend_id']]),
            )) > 0)
            ->count();
        $diagnostics = json_encode([
            'parent_backend_id' => $parentBackendId,
            'worker_activity' => $activityDiagnostics,
        ], JSON_PARTIAL_OUTPUT_ON_ERROR);

        expect(count($waitingWorkers))->toBe(2, $diagnostics)
            ->and($parentBlockedCount)->toBe(1, $diagnostics)
            ->and($workerBlockedCount)->toBe(1, $diagnostics)
            ->and(collect($processes)->every(fn (array $worker): bool => $worker['process']->isSuccessful()))->toBeTrue($diagnostics);

        $results = collect($processes)->map(function (array $worker): array {
            $messages = array_filter(
                explode(PHP_EOL, trim($worker['process']->getOutput())),
                static fn (string $line): bool => $line !== '',
            );

            return json_decode((string) end($messages), true, flags: JSON_THROW_ON_ERROR);
        });
        expect($results->every(fn (array $result): bool => ($result['saved'] ?? false) === true))->toBeTrue();

        $savedPromotion = Promotion::query()->with('extraPoints')->findOrFail($promotion->id);
        $savedWindows = $savedPromotion->extraPoints
            ->map(fn ($window): array => [
                'weekday' => $window->weekday,
                'start_time' => $window->start_time === null ? null : substr($window->start_time, 0, 5),
                'end_time' => $window->end_time === null ? null : substr($window->end_time, 0, 5),
                'multiplier' => $window->multiplier,
            ])
            ->sortBy('weekday')
            ->values()
            ->all();
        $completeVersions = collect($versions)->contains(fn (array $version): bool => $savedPromotion->reward_title === $version['reward_title']
            && $savedPromotion->target_points === $version['target_points']
            && $savedPromotion->local_start_date->toDateString() === $version['local_start_date']
            && $savedPromotion->local_end_date->toDateString() === $version['local_end_date']
            && $savedPromotion->reward_description === $version['reward_description']
            && $savedWindows === $version['extra_points']
        );

        expect($savedPromotion->business_id)->toBe($business->id)
            ->and($savedPromotion->status)->toBe(PromotionStatus::Draft)
            ->and($completeVersions)->toBeTrue();
    } finally {
        if ($lockHeld && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach ($processes as $worker) {
            if ($worker['process']->isRunning()) {
                $worker['process']->stop(1);
            }
        }

        DB::transaction(function () use ($promotion, $business, $owner): void {
            $promotion->extraPoints()->delete();
            $promotion->delete();
            $business->delete();
            $owner->delete();
        });
    }
});

/**
 * Build a draft input for competing database-connection operations.
 *
 * @param  string  $title  Reward title distinguishing this candidate update.
 * @param  int  $target  Positive visit target for the generated draft.
 * @param  list<array{weekday: int, start_time: ?string, end_time: ?string, multiplier: int}>  $windows  Ordered complete multiplier windows included in the payload.
 * @return array{local_start_date: string, local_end_date: string, target_points: int, reward_title: string, reward_description: string, extra_points: list<array{weekday: int, start_time: ?string, end_time: ?string, multiplier: int}>} Draft fields and the supplied extra-point windows without applying domain validation.
 */
function concurrentDraftInput(string $title, int $target, array $windows): array
{
    return [
        'local_start_date' => '2030-01-01',
        'local_end_date' => '2030-01-14',
        'target_points' => $target,
        'reward_title' => $title,
        'reward_description' => 'A complete test aggregate for '.$title.'.',
        'extra_points' => $windows,
    ];
}

/**
 * Build one complete multiplier-window payload for a concurrency case.
 *
 * @param  int  $weekday  ISO weekday from 1 (Monday) through 7 (Sunday).
 * @param  string|null  $start  Inclusive window start in 24-hour HH:MM format, or null for all day.
 * @param  string|null  $end  Exclusive window end in 24-hour HH:MM format, or null for all day.
 * @param  int  $multiplier  Supported total multiplier value.
 * @return array{weekday: int, start_time: string|null, end_time: string|null, multiplier: int} Complete rule entry.
 */
function concurrentDraftWindow(int $weekday, ?string $start, ?string $end, int $multiplier): array
{
    return [
        'weekday' => $weekday,
        'start_time' => $start,
        'end_time' => $end,
        'multiplier' => $multiplier,
    ];
}
