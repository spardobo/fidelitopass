<?php

use App\Actions\Promotions\PublishPromotion;
use App\Actions\Promotions\SavePromotionDraft;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('recomputes replacement occupancy when cancellation wins the Business lock', function () {
    $race = cancellationPublicationRace('cancel', 'publish');

    expect($race['first_outcome'])->toBe('cancelled')
        ->and($race['second_outcome'])->toBe('published')
        ->and($race['original']['status'])->toBe(PromotionStatus::Cancelled->value)
        ->and($race['original']['cancelled_at'])->not->toBeNull()
        ->and($race['original']['cancelled_at'])->toBe($race['original']['updated_at'])
        ->and($race['replacement']['status'])->toBe(PromotionStatus::Published->value)
        ->and($race['replacement']['starts_at'])->toBe($race['original_before']['starts_at'])
        ->and($race['replacement']['ends_at'])->toBe($race['original_before']['ends_at'])
        ->and($race['original']['local_start_date'])->toBe($race['original_before']['local_start_date'])
        ->and($race['original']['local_end_date'])->toBe($race['original_before']['local_end_date'])
        ->and($race['published_count'])->toBe(1)
        ->and($race['original']['timezone_snapshot'])->toBe($race['original_before']['timezone_snapshot'])
        ->and($race['original']['target_points'])->toBe($race['original_before']['target_points'])
        ->and($race['original']['reward_title'])->toBe($race['original_before']['reward_title'])
        ->and($race['original']['reward_description'])->toBe($race['original_before']['reward_description']);
});

it('rejects replacement publication when it wins the Business lock before cancellation', function () {
    $race = cancellationPublicationRace('publish', 'cancel');

    expect($race['first_outcome'])->toBe('rejected')
        ->and($race['second_outcome'])->toBe('cancelled')
        ->and($race['original']['status'])->toBe(PromotionStatus::Cancelled->value)
        ->and($race['original']['cancelled_at'])->not->toBeNull()
        ->and($race['original']['cancelled_at'])->toBe($race['original']['updated_at'])
        ->and($race['original']['starts_at'])->toBe($race['original_before']['starts_at'])
        ->and($race['original']['ends_at'])->toBe($race['original_before']['ends_at'])
        ->and($race['original']['local_start_date'])->toBe($race['original_before']['local_start_date'])
        ->and($race['original']['local_end_date'])->toBe($race['original_before']['local_end_date'])
        ->and($race['original']['timezone_snapshot'])->toBe($race['original_before']['timezone_snapshot'])
        ->and($race['original']['target_points'])->toBe($race['original_before']['target_points'])
        ->and($race['original']['reward_title'])->toBe($race['original_before']['reward_title'])
        ->and($race['original']['reward_description'])->toBe($race['original_before']['reward_description'])
        ->and($race['replacement'])->toBe($race['replacement_before'])
        ->and($race['replacement']['status'])->toBe(PromotionStatus::Draft->value)
        ->and($race['replacement']['starts_at'])->toBeNull()
        ->and($race['replacement']['ends_at'])->toBeNull()
        ->and($race['replacement']['timezone_snapshot'])->toBeNull()
        ->and($race['published_count'])->toBe(0);
});

/**
 * Run two real action processes with a controlled Business lock order and verify PostgreSQL blocks the second.
 *
 * @param  'cancel'|'publish'  $firstOperation  Action that owns the Business lock first.
 * @param  'cancel'|'publish'  $secondOperation  Action started while the first process owns that lock.
 * @return array{first_outcome: string, second_outcome: string, original: array<string, mixed>, original_before: array<string, mixed>, replacement: array<string, mixed>, replacement_before: array<string, mixed>, published_count: int} Observed race results and immutable row snapshots.
 *
 * @throws JsonException When a worker emits malformed JSON.
 * @throws RuntimeException When process coordination, lock observation, or a worker fails.
 */
function cancellationPublicationRace(string $firstOperation, string $secondOperation): array
{
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('testing');

    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $startDate = (string) DB::scalar('SELECT ((clock_timestamp() AT TIME ZONE ?)::date + 2)::date', [$business->timezone]);
    $endDate = CarbonImmutable::parse($startDate)->addDays(2)->toDateString();
    $originalDraft = app(SavePromotionDraft::class)->handle($owner, [
        'local_start_date' => $startDate,
        'local_end_date' => $endDate,
        'target_points' => 8,
        'reward_title' => 'Original scheduled promotion',
        'reward_description' => 'Original frozen reward terms.',
        'extra_points' => [],
    ]);
    $original = app(PublishPromotion::class)->handle($owner, $originalDraft, $business->timezone);
    $replacement = app(SavePromotionDraft::class)->handle($owner, [
        'local_start_date' => $startDate,
        'local_end_date' => $endDate,
        'target_points' => 9,
        'reward_title' => 'Replacement scheduled promotion',
        'reward_description' => 'Replacement draft terms.',
        'extra_points' => [],
    ]);
    $originalBefore = $original->fresh()->getRawOriginal();
    $replacementBefore = $replacement->fresh()->getRawOriginal();
    $originalId = (int) $original->getKey();
    $replacementId = (int) $replacement->getKey();

    $gatePath = tempnam(sys_get_temp_dir(), 'promotion-race-');
    if ($gatePath === false) {
        throw new RuntimeException('Unable to create a temporary race coordination file.');
    }
    $first = null;
    $second = null;

    try {
        DB::connection()->commit();
        if (! unlink($gatePath)) {
            throw new RuntimeException('Unable to initialize the temporary race coordination file.');
        }
        $first = cancellationRaceWorker($owner->id, $business->id, $firstOperation === 'cancel' ? $originalId : $replacementId, $firstOperation, $business->timezone, $gatePath);
        $first->start();
        $firstBackendId = cancellationRaceBackendId($first);
        cancellationRaceWaitForBusinessLock($firstBackendId);

        $second = cancellationRaceWorker($owner->id, $business->id, $secondOperation === 'cancel' ? $originalId : $replacementId, $secondOperation, $business->timezone);
        $second->start();
        $secondBackendId = cancellationRaceBackendId($second);
        cancellationRaceWaitForBlockedBy($secondBackendId, $firstBackendId);

        if (file_put_contents($gatePath, 'run') === false) {
            throw new RuntimeException('Unable to release the first action worker.');
        }

        $first->wait();
        $second->wait();
        $firstOutcome = cancellationRaceOutcome($first);
        $secondOutcome = cancellationRaceOutcome($second);

        if (! $first->isSuccessful() || ! $second->isSuccessful()) {
            throw new RuntimeException("Race worker failed: {$first->getErrorOutput()} {$second->getErrorOutput()}");
        }

        return [
            'first_outcome' => $firstOutcome,
            'second_outcome' => $secondOutcome,
            'original' => Promotion::query()->findOrFail($originalId)->getRawOriginal(),
            'original_before' => $originalBefore,
            'replacement' => Promotion::query()->findOrFail($replacementId)->getRawOriginal(),
            'replacement_before' => $replacementBefore,
            'published_count' => (int) Promotion::query()->where('business_id', $business->id)->where('status', PromotionStatus::Published->value)->count(),
        ];
    } finally {
        foreach ([$first, $second] as $process) {
            if ($process instanceof Process && $process->isRunning()) {
                $process->stop(1);
            }
        }

        @unlink($gatePath);
        DB::transaction(function () use ($originalId, $replacementId, $business, $owner): void {
            Promotion::query()->whereKey([$originalId, $replacementId])->delete();
            $business->delete();
            $owner->delete();
        });
    }
}

/**
 * Start a worker that executes the requested action, optionally holding Business first until released by the test.
 *
 * @param  int  $actorId  Owner authorized to run the action.
 * @param  int  $businessId  Business row used to serialize both actions.
 * @param  int  $promotionId  Original or replacement Promotion passed to the action.
 * @param  'cancel'|'publish'  $operation  Action dispatched by the worker.
 * @param  string  $timezone  Current Business timezone confirmed for publication.
 * @param  string|null  $gatePath  Temporary file path used to release the first lock holder.
 * @return Process Process configured for the isolated PostgreSQL testing database.
 *
 * @throws JsonException When the worker payload cannot be encoded.
 */
function cancellationRaceWorker(int $actorId, int $businessId, int $promotionId, string $operation, string $timezone, ?string $gatePath = null): Process
{
    $payload = base64_encode(json_encode([
        'actor_id' => $actorId,
        'business_id' => $businessId,
        'promotion_id' => $promotionId,
        'operation' => $operation,
        'timezone' => $timezone,
        'gate_path' => $gatePath,
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
        $connection = DB::connection();
        if ($payload['gate_path'] !== null) {
            $connection->beginTransaction();
            DB::table('businesses')->where('id', $payload['business_id'])->lockForUpdate()->first();
        }
        fwrite(STDOUT, json_encode(['backend_id' => (int) DB::scalar('SELECT pg_backend_pid()')], JSON_THROW_ON_ERROR).PHP_EOL);
        fflush(STDOUT);
        if ($payload['gate_path'] !== null) {
            $deadline = hrtime(true) + 8_000_000_000;
            while (! is_file($payload['gate_path']) && hrtime(true) < $deadline) {
                usleep(10_000);
            }
            if (! is_file($payload['gate_path'])) {
                fwrite(STDERR, "Timed out waiting for the test to release the Business lock.\n");
                exit(3);
            }
        }
        $owner = App\Models\User::query()->findOrFail($payload['actor_id']);
        $promotion = App\Models\Promotion::query()->findOrFail($payload['promotion_id']);
        try {
            if ($payload['operation'] === 'cancel') {
                app(App\Actions\Promotions\CancelPromotion::class)->handle($owner, $promotion);
                $outcome = 'cancelled';
            } else {
                app(App\Actions\Promotions\PublishPromotion::class)->handle($owner, $promotion, $payload['timezone']);
                $outcome = 'published';
            }
        } catch (Illuminate\Validation\ValidationException) {
            $outcome = 'rejected';
        }
        if ($payload['gate_path'] !== null) {
            $connection->commit();
        }
        fwrite(STDOUT, json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR).PHP_EOL);
    PHP;

    return new Process(
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
        20,
    );
}

/**
 * Wait until a worker announces its PostgreSQL backend or fail after a bounded deadline.
 *
 * @param  Process  $process  Started worker process whose output is polled.
 * @return int PostgreSQL backend process identifier announced by the worker.
 *
 * @throws JsonException When a worker emits malformed JSON.
 * @throws RuntimeException When the worker exits or the backend announcement deadline expires.
 */
function cancellationRaceBackendId(Process $process): int
{
    $deadline = hrtime(true) + 8_000_000_000;
    do {
        foreach (explode(PHP_EOL, $process->getOutput()) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($message) && isset($message['backend_id'])) {
                return (int) $message['backend_id'];
            }
        }
        if (! $process->isRunning()) {
            throw new RuntimeException("Worker exited before announcing its backend: {$process->getErrorOutput()}");
        }
        usleep(10_000);
    } while (hrtime(true) < $deadline);

    throw new RuntimeException('Timed out waiting for a race worker backend identifier.');
}

/**
 * Require PostgreSQL to report the first worker as a granted waiter-visible Business lock holder.
 *
 * @param  int  $backendId  PostgreSQL backend expected to own the Business row lock.
 *
 * @throws RuntimeException When PostgreSQL does not expose the granted lock before timeout.
 */
function cancellationRaceWaitForBusinessLock(int $backendId): void
{
    $deadline = hrtime(true) + 5_000_000_000;
    do {
        DB::select('SELECT pg_stat_clear_snapshot()');
        $activity = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = ? AND relation = 'businesses'::regclass AND granted) AS holds_lock",
            [$backendId],
        );
        if ($activity?->holds_lock) {
            return;
        }
        usleep(10_000);
    } while (hrtime(true) < $deadline);

    throw new RuntimeException('PostgreSQL did not confirm the first worker held a granted Business lock.');
}

/**
 * Require the second backend to be waiting on a PostgreSQL lock held by the first backend.
 *
 * @param  int  $waitingBackendId  Backend running the second application action.
 * @param  int  $holderBackendId  Backend whose Business lock serializes the operation order.
 *
 * @throws RuntimeException When PostgreSQL never reports the specific lock wait.
 */
function cancellationRaceWaitForBlockedBy(int $waitingBackendId, int $holderBackendId): void
{
    $deadline = hrtime(true) + 5_000_000_000;
    do {
        DB::select('SELECT pg_stat_clear_snapshot()');
        $activity = DB::selectOne(
            'SELECT wait_event_type, ? = ANY(pg_blocking_pids(pid)) AS blocked_by_holder FROM pg_stat_activity WHERE pid = ?',
            [$holderBackendId, $waitingBackendId],
        );
        if ($activity?->wait_event_type === 'Lock' && $activity?->blocked_by_holder) {
            return;
        }
        usleep(10_000);
    } while (hrtime(true) < $deadline);

    throw new RuntimeException('PostgreSQL did not confirm the second action was blocked by the first lock holder.');
}

/**
 * Read the final action outcome emitted by a completed worker.
 *
 * @param  Process  $process  Completed worker process.
 * @return string Action result, including rejection for expected validation failures.
 *
 * @throws JsonException When the worker emits malformed JSON.
 * @throws RuntimeException When no outcome message is present.
 */
function cancellationRaceOutcome(Process $process): string
{
    foreach (array_reverse(explode(PHP_EOL, trim($process->getOutput()))) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (is_array($message) && isset($message['outcome'])) {
            return $message['outcome'];
        }
    }

    throw new RuntimeException("Worker exited without an action outcome: {$process->getErrorOutput()}");
}
