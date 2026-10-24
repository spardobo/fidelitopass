<?php

use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Models\RewardEntitlement;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresDatabaseTruncation;

uses(PostgresDatabaseTruncation::class);

afterEach(function () {
    $this->truncateTablesForAllConnections();
});

it('serializes public commands for duplicate UUIDs and independent target-crossing Visits', function (bool $sameOperation) {
    [$business, $pass, $promotion] = concurrentVisitFixture($sameOperation ? 1 : 2);
    $operationId = (string) Str::uuid();
    $processes = [
        visitWorker($business->user_id, $operationId),
        visitWorker($business->user_id, $sameOperation ? $operationId : (string) Str::uuid()),
    ];
    $connection = DB::connection();
    $connection->beginTransaction();
    $business->newQuery()->whereKey($business->id)->lockForUpdate()->firstOrFail();

    try {
        foreach ($processes as $process) {
            $process->start();
        }
        expect(waitForVisitLocks($processes))->toBe(2);
        $connection->commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getErrorOutput())->toBe('');
            expect($process->getExitCode())->toBe(0);
            $results[] = visitWorkerResult($process);
        }

        expect(array_column($results, 'failure'))->toBe([null, null]);
        expect(array_sum(array_column($results, 'provider_requests')))->toBe($sameOperation ? 3 : 6);
        if ($sameOperation) {
            expect($results[0]['result'])->toBe($results[1]['result']);
        } else {
            expect($results[0]['result']['visit_id'])->not->toBe($results[1]['result']['visit_id']);
        }
        expect(Visit::query()->count())->toBe($sameOperation ? 1 : 2);
        expect((int) Visit::query()->sum('awarded_points'))->toBe($sameOperation ? 1 : 2);
        $reward = RewardEntitlement::query()->sole();
        expect($reward->customer_pass_id)->toBe($pass->id);
        expect($reward->promotion_id)->toBe($promotion->id);
        expect(Visit::query()->where('confirmed_at', $reward->getRawOriginal('unlocked_at'))->exists())->toBeTrue();
        $events = collect($results)->flatMap(fn ($result) => $result['events']);
        expect($events->where('message', 'reward.unlocked'))->toHaveCount(1);
        expect($events->where('message', 'visit.accepted'))->toHaveCount($sameOperation ? 1 : 2);
        expect($events->where('message', 'visit.replayed'))->toHaveCount($sameOperation ? 1 : 0);
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
})->with(['same global operation UUID' => true, 'distinct intentional operations' => false]);

it('rejects a simultaneous global operation collision across Businesses without a second provider write', function () {
    [$firstBusiness] = concurrentVisitFixture(1);
    [$secondBusiness] = concurrentVisitFixture(1, 'b');
    $operationId = (string) Str::uuid();
    $processes = [visitWorker($firstBusiness->user_id, $operationId), visitWorker($secondBusiness->user_id, $operationId, 'b')];
    $connection = DB::connection();
    $connection->beginTransaction();
    Business::query()->whereKey([$firstBusiness->id, $secondBusiness->id])->orderBy('id')->lockForUpdate()->get();

    try {
        foreach ($processes as $process) {
            $process->start();
        }
        expect(waitForVisitLocks($processes))->toBe(2);
        $connection->commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getErrorOutput())->toBe('');
            expect($process->getExitCode())->toBe(0);
            $results[] = visitWorkerResult($process);
        }

        expect(collect($results)->where('failure', 'operation_conflict'))->toHaveCount(1);
        expect(collect($results)->whereNotNull('result'))->toHaveCount(1);
        expect(array_sum(array_column($results, 'provider_requests')))->toBe(3);
        $this->assertDatabaseCount('visits', 1);
        $this->assertDatabaseCount('reward_entitlements', 1);
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
});

it('captures PostgreSQL time after a pass-lock wait crosses the exclusive Promotion end', function () {
    [$business, $pass, $promotion] = concurrentVisitFixture(1);
    $end = DB::scalar("SELECT clock_timestamp() + interval '4 seconds'");
    $promotion->forceFill(['ends_at' => $end])->save();
    $process = visitWorker($business->user_id, (string) Str::uuid());
    $connection = DB::connection();
    $connection->beginTransaction();
    $pass->newQuery()->whereKey($pass->id)->lockForUpdate()->firstOrFail();

    try {
        $process->start();
        expect(waitForVisitLocks([$process]))->toBe(1);
        expect((bool) DB::scalar('SELECT clock_timestamp() < ?::timestamptz', [$end]))->toBeTrue();
        $deadline = hrtime(true) + 6_000_000_000;
        while ((bool) DB::scalar('SELECT clock_timestamp() < ?::timestamptz', [$end])) {
            if (hrtime(true) >= $deadline) {
                test()->fail('PostgreSQL clock did not reach the fixture deadline.');
            }
            usleep(10_000);
        }
        $connection->commit();
        $process->wait();

        expect($process->getErrorOutput())->toBe('');
        expect($process->getExitCode())->toBe(0);
        $result = visitWorkerResult($process);
        expect($result['failure'])->toBe('promotion_unavailable');
        expect($result['result'])->toBeNull();
        expect($result['provider_requests'])->toBe(0);
        expect(array_column($result['events'], 'message'))->toBe(['visit.rejected']);
        $this->assertDatabaseCount('visits', 0);
        $this->assertDatabaseCount('reward_entitlements', 0);
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        if ($process->isRunning()) {
            $process->stop(1);
        }
    }
});

/**
 * Creates committed same-Business fixtures without wrapping the command in a test transaction.
 *
 * @param  int  $target  Target reached by the concurrent regular Visits.
 * @param  string  $tokenByte  Distinct synthetic token byte for cross-Business fixtures.
 * @return array{Business, CustomerPass, Promotion} Persistent public-command fixtures.
 */
function concurrentVisitFixture(int $target, string $tokenByte = 'a'): array
{
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    expect(DB::connection()->getDatabaseName())->toBe('testing');
    expect(DB::transactionLevel())->toBe(0);
    $business = Business::factory()->create(['timezone' => 'UTC', 'pass_background_color' => '#123456']);
    $pass = $business->customerPasses()->create([
        'manual_code' => '00007',
        'validation_token_hash' => hash('sha256', str_repeat($tokenByte, 64)),
    ]);
    $pass->update(['wallet_object_id' => '123456.pass_'.$pass->public_id]);
    $promotion = $business->promotions()->make();
    $promotion->forceFill([
        'status' => 'published',
        'target_points' => $target,
        'reward_title' => 'Concurrent Reward',
        'timezone_snapshot' => 'UTC',
        'starts_at' => '2000-01-01 00:00:00+00',
        'ends_at' => '2100-01-01 00:00:00+00',
    ])->save();

    return [$business, $pass, $promotion];
}

/**
 * Configures a separate application process using the real Action and isolated native Google transport.
 *
 * @param  int  $actorId  Committed owner identity.
 * @param  string  $operationId  Intentional public-command operation UUID.
 * @param  string  $tokenByte  Synthetic barcode byte matching this worker's fixture.
 * @return Process Foreground-owned worker, started by the test only after acquiring its barrier lock.
 */
function visitWorker(int $actorId, string $operationId, string $tokenByte = 'a'): Process
{
    $worker = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (DB::connection()->getDatabaseName() !== 'testing') {
            fwrite(STDERR, "Refusing to use a non-testing database.\n");
            exit(2);
        }
        config(['logging.default' => 'null', 'cache.default' => 'array']);
        $owner = App\Models\User::query()->findOrFail((int) $argv[1]);
        $business = $owner->business;
        $pass = $business->customerPasses()->sole();
        $http = new Tests\Support\GoogleWalletHttpFake;
        app()->instance(App\Integrations\GoogleWallet\GoogleWalletClient::class, new App\Integrations\GoogleWallet\GoogleWalletClient($http->client));
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $configuration = Mockery::mock(App\Integrations\GoogleWallet\GoogleWalletConfiguration::class);
        $configuration->shouldReceive('load')->with(true)->andReturn([
            'issuer_id' => '123456',
            'account' => [
                'type' => 'service_account', 'client_id' => 'synthetic-client',
                'client_email' => 'wallet@platform.iam.gserviceaccount.com', 'private_key' => $pem,
            ],
            'origin' => 'https://passes.example.test',
            'logo_url' => 'https://passes.example.test/logo.png',
            'connect_timeout' => 3, 'timeout' => 10,
        ]);
        app()->instance(App\Integrations\GoogleWallet\GoogleWalletConfiguration::class, $configuration);
        $remote = [
            'id' => $pass->wallet_object_id, 'classId' => '123456.business_'.$business->public_id,
            'accountId' => $pass->manual_code, 'barcode' => ['type' => 'QR_CODE', 'value' => str_repeat($argv[3], 64)],
        ];
        $http->respondWith([
            'https://oauth2.googleapis.com/token' => Tests\Support\GoogleWalletHttpFake::json(['access_token' => 'synthetic', 'expires_in' => 3600]),
            'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/'.$pass->wallet_object_id => function ($request) use (&$remote) {
                if ($request->method() === 'PATCH') {
                    $remote = array_replace($remote, $request->data());
                }
                return Tests\Support\GoogleWalletHttpFake::json($remote);
            },
        ]);
        $events = [];
        Log::listen(function ($event) use (&$events) {
            $events[] = ['message' => $event->message, 'level' => $event->level, 'context' => $event->context];
        });
        fwrite(STDOUT, json_encode(['backend_id' => (int) DB::scalar('SELECT pg_backend_pid()')]).PHP_EOL);
        fflush(STDOUT);
        $result = null;
        $failure = null;
        try {
            $result = app(App\Actions\Visits\AcceptVisit::class)->handle($owner, 'manual', '00007', $argv[2]);
        } catch (App\Exceptions\VisitAcceptanceException $exception) {
            $failure = $exception->failure->value;
        }
        fwrite(STDOUT, json_encode([
            'result' => $result, 'failure' => $failure,
            'provider_requests' => count($http->history), 'events' => $events,
        ], JSON_THROW_ON_ERROR).PHP_EOL);
        PHP;

    return new Process([PHP_BINARY, '-r', $worker, (string) $actorId, $operationId, $tokenByte], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => 'testing',
        'DB_URL' => '',
        'DB_HOST' => config('database.connections.pgsql.host'),
        'DB_PORT' => (string) config('database.connections.pgsql.port'),
        'DB_USERNAME' => config('database.connections.pgsql.username'),
        'DB_PASSWORD' => config('database.connections.pgsql.password'),
    ], null, 12);
}

/**
 * Observes real PostgreSQL lock waits instead of using a sleep as a start barrier.
 *
 * @param  list<Process>  $processes  Workers whose backend identifiers are announced before invoking the Action.
 * @return int Number of workers actually waiting on a database lock.
 */
function waitForVisitLocks(array $processes): int
{
    $deadline = hrtime(true) + 5_000_000_000;
    do {
        DB::select('SELECT pg_stat_clear_snapshot()');
        $waiting = 0;
        foreach ($processes as $process) {
            foreach (explode(PHP_EOL, $process->getOutput()) as $line) {
                $message = json_decode($line, true);
                if (isset($message['backend_id'])) {
                    $waiting += (int) (DB::scalar('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$message['backend_id']]) === 'Lock');
                }
            }
        }
        if ($waiting === count($processes)) {
            return $waiting;
        }
        usleep(10_000);
    } while (hrtime(true) < $deadline);

    return $waiting;
}

/**
 * Reads the worker's final public outcome without discarding preceding diagnostic output.
 *
 * @param  Process  $process  Completed worker with retained stdout and stderr.
 * @return array<string, mixed> Public result, provider request count and observed safe events.
 */
function visitWorkerResult(Process $process): array
{
    $lines = array_filter(explode(PHP_EOL, trim($process->getOutput())));

    return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
}
