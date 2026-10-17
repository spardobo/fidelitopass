<?php

use App\Enums\InspectionFailure;
use App\Exceptions\CustomerPassInspectionException;
use App\Integrations\GoogleWallet\GoogleWalletClient;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Models\User;
use App\Support\DatabaseClock;
use App\Support\VisitReceipt;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\GoogleWalletHttpFake;
use Tests\Support\PostgresDatabaseTruncation;

/** Guards cleanup before the framework can reset any PostgreSQL data. */
trait GuardsVisitReceiptDatabase
{
    use PostgresDatabaseTruncation;

    /**
     * Refuses configured or actual databases outside the isolated testing runtime.
     *
     * @throws LogicException If the runtime or database is not the testing target.
     */
    protected function beforeTruncatingDatabase(): void
    {
        if (
            ! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'testing'
            || DB::selectOne('SELECT current_database() AS name')->name !== 'testing'
        ) {
            throw new LogicException('Refusing to reset a non-testing database.');
        }
    }
}

uses(GuardsVisitReceiptDatabase::class);

beforeEach(function () {
    $this->http = new GoogleWalletHttpFake;
    app()->instance(GoogleWalletClient::class, new GoogleWalletClient($this->http->client));
    Log::spy();
});

afterEach(function () {
    $this->truncateTablesForAllConnections();
});

it('reads the original prefix and frozen reward terms after later Visits and replacement offers', function (string $state, int $points, bool $unlocked) {
    [$business, $pass, $promotion, $operationId, $visitId] = receiptFixture($points);
    if (! $unlocked) {
        DB::table('visits')->insert([
            'business_id' => $business->id,
            'customer_pass_id' => $pass->id,
            'promotion_id' => $promotion->id,
            'operation_id' => (string) Str::uuid(),
            'awarded_points' => 5,
            'confirmed_by_user_id' => $business->user_id,
            'confirmed_at' => '2030-01-02 12:00:00.123456+00',
        ]);
    }
    $promotion->forceFill($state === 'expired'
        ? ['ends_at' => '2030-01-03 00:00:00+00']
        : ['status' => 'cancelled', 'cancelled_at' => '2030-01-03 00:00:00+00'])->save();
    $replacement = $business->promotions()->make();
    $replacement->forceFill([
        'status' => 'published',
        'target_points' => 50,
        'reward_title' => 'Otra recompensa',
        'timezone_snapshot' => 'Europe/Madrid',
        'starts_at' => '2030-01-03 00:00:00+00',
        'ends_at' => '2099-01-01 00:00:00+00',
    ])->save();
    $business->update(['timezone' => 'Europe/Madrid']);
    $actor = $business->user;
    $before = receiptStoredFacts();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $receipt = app(VisitReceipt::class)->find($actor, 'manual', " \t00007\n", strtoupper($operationId));
    $again = app(VisitReceipt::class)->find($actor, 'token', str_repeat('a', 64), $operationId);

    expect($receipt)->toBe([
        'visit_id' => $visitId,
        'operation_id' => $operationId,
        'customer_pass_id' => $pass->id,
        'promotion_id' => $promotion->id,
        'points_awarded' => $points,
        'confirmed_at' => '2030-01-02T12:00:00.123456Z',
        'promotion' => [
            'reward_title' => 'Pan artesanal',
            'reward_description' => 'Una pieza a elección',
            'target_points' => 4,
            'earned_points' => 2 + $points,
            'reward_unlocked' => $unlocked,
        ],
    ]);
    expect($again)->toBe($receipt);
    expect(receiptStoredFacts())->toBe($before);
    expect($writes)->toBeEmpty();
    expect($this->http->history)->toBeEmpty();
    expect(RateLimiter::attempts('pass-lookup:'.$actor->id))->toBe(0);
    expect(json_encode($receipt))->not->toContain(str_repeat('a', 64), '00007', 'validation_token_hash', 'wallet_object_id');
    Log::shouldNotHaveReceived('log');
    Log::shouldNotHaveReceived('info');
})->with([
    'expired noncompleting' => ['expired', 1, false],
    'cancelled exact unlock' => ['cancelled', 2, true],
    'cancelled overshoot unlock' => ['cancelled', 5, true],
]);

it('excludes other passes and Promotions from the original insertion prefix', function () {
    [$business, $pass, $promotion] = receiptFixture();
    $otherPass = $business->customerPasses()->create([
        'manual_code' => '00008',
        'validation_token_hash' => hash('sha256', str_repeat('b', 64)),
    ]);
    $oldPromotion = $business->promotions()->make();
    $oldPromotion->forceFill([
        'status' => 'published',
        'target_points' => 20,
        'reward_title' => 'Premio anterior',
        'timezone_snapshot' => 'America/Bogota',
        'starts_at' => '2029-01-01 00:00:00+00',
        'ends_at' => '2030-01-01 00:00:00+00',
    ])->save();
    foreach ([[$otherPass->id, $promotion->id], [$pass->id, $oldPromotion->id]] as [$passId, $promotionId]) {
        DB::table('visits')->insert([
            'business_id' => $business->id,
            'customer_pass_id' => $passId,
            'promotion_id' => $promotionId,
            'operation_id' => (string) Str::uuid(),
            'awarded_points' => 3,
            'confirmed_by_user_id' => $business->user_id,
            'confirmed_at' => $promotionId === $oldPromotion->id ? '2029-12-31 12:00:00+00' : '2030-01-02 12:00:00+00',
        ]);
    }
    $operationId = (string) Str::uuid();
    DB::table('visits')->insert([
        'business_id' => $business->id,
        'customer_pass_id' => $pass->id,
        'promotion_id' => $promotion->id,
        'operation_id' => $operationId,
        'awarded_points' => 1,
        'confirmed_by_user_id' => $business->user_id,
        'confirmed_at' => '2030-01-02 12:01:00+00',
    ]);
    $before = receiptStoredFacts();

    $receipt = app(VisitReceipt::class)->find($business->user, 'manual', '00007', $operationId);

    expect($receipt['promotion']['earned_points'])->toBe(4);
    expect($receipt['promotion']['reward_unlocked'])->toBeTrue();
    expect($receipt['points_awarded'])->toBe(1);
    expect(receiptStoredFacts())->toBe($before);
    expect($this->http->history)->toBeEmpty();
    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(0);
});

it('returns ordinary absence for unknown or foreign operation UUIDs without revealing stored facts', function (string $kind) {
    [$business, $pass, , $operationId] = receiptFixture();
    if ($kind === 'foreign business') {
        $business = Business::factory()->create();
        $pass = $business->customerPasses()->create([
            'manual_code' => '00007',
            'validation_token_hash' => hash('sha256', str_repeat('b', 64)),
        ]);
    } elseif ($kind === 'different pass') {
        $pass = $business->customerPasses()->create([
            'manual_code' => '00008',
            'validation_token_hash' => hash('sha256', str_repeat('b', 64)),
        ]);
    } else {
        $operationId = (string) Str::uuid();
    }
    $actor = $business->user;
    $before = receiptStoredFacts();

    expect(app(VisitReceipt::class)->find($actor, 'manual', $pass->manual_code, $operationId))->toBeNull();

    expect(receiptStoredFacts())->toBe($before);
    expect($this->http->history)->toBeEmpty();
    expect(RateLimiter::attempts('pass-lookup:'.$actor->id))->toBe(0);
    Log::shouldNotHaveReceived('log');
})->with(['foreign business', 'different pass', 'unknown']);

it('rejects current credential and actor failures through the Inspector only once', function (string $kind, string $reason, int $attempts) {
    [$business, $pass, , $operationId] = receiptFixture();
    $actor = $business->user;
    $credential = str_repeat('a', 64);
    if ($kind === 'retired token') {
        $pass->update(['validation_token_hash' => hash('sha256', str_repeat('b', 64))]);
    } elseif ($kind === 'foreign token') {
        $actor = Business::factory()->create()->user;
    } elseif ($kind === 'unverified') {
        $actor->forceFill(['email_verified_at' => null])->save();
    } elseif ($kind === 'anonymous') {
        $actor = null;
    } elseif ($kind === 'without business') {
        $actor = User::factory()->create();
    } elseif ($kind === 'malformed token') {
        $credential .= ' ';
    } elseif ($kind === 'throttled') {
        config(['pass_lookup.max_invalid_attempts' => 1]);
        RateLimiter::hit('pass-lookup:'.$actor->id, 60);
    }
    $before = receiptStoredFacts();

    expect(fn () => app(VisitReceipt::class)->find($actor, 'token', $credential, $operationId))
        ->toThrow(CustomerPassInspectionException::class, $reason);

    expect(receiptStoredFacts())->toBe($before);
    expect($this->http->history)->toBeEmpty();
    expect(RateLimiter::attempts('pass-lookup:'.($actor?->id ?? $business->user_id)))->toBe($attempts);
    if ($reason === 'forbidden') {
        Log::shouldNotHaveReceived('log');
    } else {
        Log::shouldHaveReceived('log')->once();
    }
})->with([
    ['retired token', 'invalid_credential', 1],
    ['foreign token', 'invalid_credential', 1],
    ['malformed token', 'invalid_credential', 1],
    ['unverified', 'forbidden', 0],
    ['anonymous', 'forbidden', 0],
    ['without business', 'forbidden', 0],
    ['throttled', 'invalid_attempt_limit', 1],
]);

it('propagates the Inspector failure instance without a second outcome log', function () {
    [$business, , , $operationId] = receiptFixture();
    $failure = new CustomerPassInspectionException(InspectionFailure::AuthoritativeReadFailed);
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()->andThrow($failure);
    $before = receiptStoredFacts();

    try {
        app(VisitReceipt::class)->find($business->user, 'manual', '00007', $operationId);
        test()->fail('Expected original inspection failure.');
    } catch (CustomerPassInspectionException $caught) {
        expect($caught)->toBe($failure);
    }

    Log::shouldHaveReceived('log')->once();
    expect(receiptStoredFacts())->toBe($before);
    expect($this->http->history)->toBeEmpty();
    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(0);
});

it('returns absence for malformed operation input before any SQL or credential attempt', function (string $operationId) {
    [$business] = receiptFixture();
    $actor = $business->user;
    $before = receiptStoredFacts();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(app(VisitReceipt::class)->find($actor, 'manual', 'invalid', $operationId))->toBeNull();

    expect($queries)->toBeEmpty();
    expect(receiptStoredFacts())->toBe($before);
    expect(RateLimiter::attempts('pass-lookup:'.$actor->id))->toBe(0);
    expect($this->http->history)->toBeEmpty();
    Log::shouldNotHaveReceived('log');
})->with(['', 'not-a-uuid', "00000000-0000-0000-0000-000000000000\n"]);

it('sanitizes native receipt query failures once without changing known committed facts', function () {
    [$business, $pass, , $operationId] = receiptFixture();
    $actor = $business->user;
    $before = receiptStoredFacts();
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $observer = clone $dispatcher;
    $native = new QueryException('pgsql', 'select private-receipt', ['00007', str_repeat('a', 64)], new PDOException('private-native'));
    $observer->listen(QueryExecuted::class, function (QueryExecuted $query) use ($native): void {
        if (
            str_contains($query->sql, 'FROM visits AS visit')
            && str_contains($query->sql, 'visit.operation_id')
        ) {
            throw $native;
        }
    });
    $connection->setEventDispatcher($observer);
    $payload = null;
    Log::shouldReceive('log')->once()->with('error', 'customer_pass.lookup_unavailable', Mockery::capture($payload));

    try {
        app(VisitReceipt::class)->find($actor, 'manual', $pass->manual_code, $operationId);
        test()->fail('Expected receipt read failure.');
    } catch (CustomerPassInspectionException $failure) {
        expect($failure->failure)->toBe(InspectionFailure::AuthoritativeReadFailed);
        expect($failure->getPrevious())->toBeNull();
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }

    expect($payload['previous_class'])->toBe(QueryException::class);
    expect($payload['operation_id'])->toBe($operationId);
    expect($payload['outcome'])->toBe('unavailable');
    foreach ($payload['exception_frames'] as $frame) {
        expect(array_diff(array_keys($frame), ['file', 'line', 'class', 'function']))->toBeEmpty();
    }
    expect(json_encode($payload))->not->toContain('00007', str_repeat('a', 64), 'private-receipt', 'private-native');
    expect(receiptStoredFacts())->toBe($before);
    expect(RateLimiter::attempts('pass-lookup:'.$actor->id))->toBe(0);
    expect($this->http->history)->toBeEmpty();
});

/**
 * Stores an original committed prefix without invoking acceptance or Google.
 *
 * @param  int  $points  Original Visit award.
 * @return array{Business, CustomerPass, Promotion, string, int} Owned facts and original operation identity.
 */
function receiptFixture(int $points = 1): array
{
    $business = Business::factory()->create(['timezone' => 'America/Bogota']);
    RateLimiter::clear('pass-lookup:'.$business->user_id);
    $pass = $business->customerPasses()->create([
        'manual_code' => '00007',
        'validation_token_hash' => hash('sha256', str_repeat('a', 64)),
    ]);
    $promotion = $business->promotions()->make();
    $promotion->forceFill([
        'status' => 'published',
        'target_points' => 4,
        'reward_title' => 'Pan artesanal',
        'reward_description' => 'Una pieza a elección',
        'timezone_snapshot' => 'America/Bogota',
        'starts_at' => '2030-01-01 00:00:00+00',
        'ends_at' => '2030-02-01 00:00:00+00',
    ])->save();
    foreach ([2, $points] as $award) {
        $operationId = (string) Str::uuid();
        $visitId = DB::table('visits')->insertGetId([
            'business_id' => $business->id,
            'customer_pass_id' => $pass->id,
            'promotion_id' => $promotion->id,
            'operation_id' => $operationId,
            'awarded_points' => $award,
            'confirmed_by_user_id' => $business->user_id,
            'confirmed_at' => '2030-01-02 12:00:00.123456+00',
        ]);
    }

    return [$business, $pass, $promotion, $operationId, $visitId];
}

/**
 * Captures stored facts to detect any unintended read-side mutation.
 *
 * @return array<string, array<int, array<string, mixed>>> Complete domain rows in insertion order.
 */
function receiptStoredFacts(): array
{
    $snapshot = [];
    foreach (['customer_passes', 'promotion_participations', 'visits', 'reward_entitlements'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $snapshot;
}
