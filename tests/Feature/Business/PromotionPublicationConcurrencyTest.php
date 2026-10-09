<?php

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('serializes overlapping publication attempts for one business', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('testing');

    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $promotionIds = collect(['2035-04-01', '2035-04-02'])->map(function (string $startDate) use ($business): int {
        return (int) DB::table('promotions')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'local_start_date' => $startDate,
            'local_end_date' => '2035-04-07',
            'target_points' => 8,
            'reward_title' => 'Concurrent publication',
            'status' => PromotionStatus::Draft->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
    $connection = DB::connection();
    $connection->commit();
    $connection->beginTransaction();
    $business->newQuery()->whereKey($business->id)->lockForUpdate()->first();
    $processes = [];
    $backendIds = [];
    $waitingBackendIds = [];
    $lockHeld = true;

    try {
        foreach ($promotionIds as $promotionId) {
            $payload = base64_encode(json_encode([
                'actor_id' => $owner->id,
                'promotion_id' => $promotionId,
                'confirmed_timezone' => 'UTC',
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
                $owner = App\Models\User::query()->findOrFail($payload['actor_id']);
                $promotion = App\Models\Promotion::query()->findOrFail($payload['promotion_id']);
                try {
                    app(App\Actions\Promotions\PublishPromotion::class)->handle($owner, $promotion, $payload['confirmed_timezone']);
                    $outcome = 'published';
                } catch (Illuminate\Validation\ValidationException) {
                    $outcome = 'rejected';
                }
                fwrite(STDOUT, json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR).PHP_EOL);
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
            $processes[] = $process;
        }

        $deadline = hrtime(true) + 5_000_000_000;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');

            foreach ($processes as $index => $process) {
                foreach (explode(PHP_EOL, $process->getOutput()) as $line) {
                    $message = json_decode($line, true);
                    if (is_array($message) && isset($message['backend_id'])) {
                        $backendIds[$index] = (int) $message['backend_id'];
                    }
                }
            }

            $waitingBackendIds = collect($backendIds)->filter(function (int $backendId): bool {
                $activity = DB::selectOne(
                    'SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?',
                    [$backendId],
                );

                return $activity?->wait_event_type === 'Lock';
            });

            if (count($waitingBackendIds) === count($processes)) {
                break;
            }

            usleep(10_000);
        } while (hrtime(true) < $deadline);

        $connection->commit();
        $lockHeld = false;
        foreach ($processes as $process) {
            $process->wait();
        }

        expect(count($waitingBackendIds))->toBe(2)
            ->and(collect($processes)->every(fn (Process $process): bool => $process->isSuccessful()))->toBeTrue();

        $outcomes = collect($processes)->map(function (Process $process): string {
            $lines = array_filter(explode(PHP_EOL, trim($process->getOutput())));
            $result = json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);

            return $result['outcome'];
        })->sort()->values()->all();

        expect($outcomes)->toBe(['published', 'rejected'])
            ->and(Promotion::query()->where('business_id', $business->id)->where('status', PromotionStatus::Published->value)->count())->toBe(1)
            ->and(Promotion::query()->where('business_id', $business->id)->where('status', PromotionStatus::Draft->value)->count())->toBe(1);
    } finally {
        if ($lockHeld && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        DB::transaction(function () use ($promotionIds, $business, $owner): void {
            Promotion::query()->whereKey($promotionIds)->delete();
            $business->delete();
            $owner->delete();
        });
    }
});
