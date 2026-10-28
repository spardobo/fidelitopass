<?php

use App\Actions\Rewards\RedeemReward;
use App\Actions\Visits\AcceptVisit;
use App\Data\Time\DatabaseTime;
use App\Enums\FailureOutcome;
use App\Exceptions\RewardRedemptionException;
use App\Exceptions\VisitAcceptanceException;
use App\Integrations\GoogleWallet\GoogleWalletClient;
use App\Integrations\GoogleWallet\GoogleWalletConfiguration;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Models\RewardEntitlement;
use App\Models\User;
use App\Models\Visit;
use App\Support\DatabaseClock;
use App\Support\RewardRedemptionReceipt;
use App\Support\VisitReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\GoogleWalletHttpFake;
use Tests\Support\PostgresDatabaseTruncation;

/** Guards cleanup before the framework can reset any PostgreSQL data. */
trait GuardsVisitAcceptanceDatabase
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

uses(GuardsVisitAcceptanceDatabase::class);

beforeEach(function () {
    app()->setLocale('es');
    $this->http = new GoogleWalletHttpFake;
    app()->instance(GoogleWalletClient::class, new GoogleWalletClient($this->http->client));

    $this->mock(GoogleWalletConfiguration::class)->shouldNotReceive('load');
    Log::spy();
});

afterEach(function () {
    $this->truncateTablesForAllConnections();
});

it('commits immutable Visit facts without contacting Google', function () {
    [$business, $pass, $promotion] = visitFixture();
    visitClock('2030-01-02 12:59:59.123456+00');
    $operationId = (string) Str::uuid();
    $before = $pass->fresh()->getAttributes();

    $result = app(AcceptVisit::class)->handle($business->user, 'manual', ' 00007 ', $operationId);

    $visit = Visit::query()->sole();
    expect($result)->toBe([
        'visit_id' => $visit->id,
        'operation_id' => $operationId,
        'customer_pass_id' => $pass->id,
        'promotion_id' => $promotion->id,
        'points_awarded' => 1,
        'confirmed_at' => '2030-01-02T12:59:59.123456Z',
    ]);
    expect($visit->confirmed_by_user_id)->toBe($business->user_id);
    expect($visit->business_id)->toBe($business->id);
    expect($visit->awarded_points)->toBe(1);
    expect($visit->confirmed_at->toISOString())->toBe($result['confirmed_at']);
    expect($pass->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('reward_entitlements', 0);
    expect($this->http->history)->toBeEmpty();
    Log::shouldHaveReceived('info')->once()->with('visit.accepted', Mockery::on(fn ($context) => $context['operation_id'] === $operationId));
    Log::shouldNotHaveReceived('log');
});

it('atomically registers and redeems locally with read-only replays', function (string $laterState) {
    [$business, $pass, $promotion] = visitFixture(['target_points' => 2]);
    $promotion->extraPoints()->create(['weekday' => 3, 'multiplier' => 3]);
    visitClock('2030-01-02 12:00:00.123456+00');
    $id = (string) Str::uuid();
    $action = app(AcceptVisit::class);

    $result = $action->handle($business->user, 'manual', $pass->manual_code, $id, $promotion->id, true);

    $reward = RewardEntitlement::query()->sole();
    expect($reward->redeemed_at?->toISOString())->toBe($result['confirmed_at']);
    expect($reward->unlocked_at->toISOString())->toBe($result['confirmed_at']);
    expect($reward->redeemed_by_user_id)->toBe($business->user_id);
    expect(array_keys($result))->toBe(['visit_id', 'operation_id', 'customer_pass_id', 'promotion_id', 'points_awarded', 'confirmed_at']);
    expect($result['points_awarded'])->toBe(3);
    $promotion->forceFill($laterState === 'expired'
        ? ['ends_at' => '2030-01-02 12:00:00+00']
        : ['status' => 'cancelled', 'cancelled_at' => '2030-01-02 12:00:00+00'])->save();
    $before = $reward->getAttributes();
    foreach ([true, true, false] as $combined) {
        expect($action->handle($business->user, 'token', str_repeat('a', 64), $id, $promotion->id, $combined))->toBe($result);
    }
    expect($reward->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 1);
    expect($this->http->history)->toBeEmpty();
    Log::shouldHaveReceived('info')->with('reward.redeemed', Mockery::on(fn ($context) => $context['operation_id'] === $id))->once();
})->with(['expired', 'cancelled']);

it('rejects compound registration below the actual goal without any partial Visit or provider work', function () {
    [$business, $pass, $promotion] = visitFixture();
    visitClock('2030-01-02 12:00:00+00');

    expect(fn () => app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid(), $promotion->id, true))
        ->toThrow(VisitAcceptanceException::class, 'reward_not_earned');

    $this->assertDatabaseCount('visits', 0);
    $this->assertDatabaseCount('reward_entitlements', 0);
    expect($this->http->history)->toBeEmpty();
});

it('never upgrades a prior Visit-only operation when the compound flag changes', function (int $target) {
    [$business, $pass, $promotion] = visitFixture(['target_points' => $target]);
    visitClock('2030-01-02 12:00:00+00');
    $id = (string) Str::uuid();
    $action = app(AcceptVisit::class);
    $original = $action->handle($business->user, 'manual', $pass->manual_code, $id, $promotion->id);
    $before = RewardEntitlement::query()->first()?->getAttributes();

    expect(fn () => $action->handle($business->user, 'manual', $pass->manual_code, $id, $promotion->id, true))
        ->toThrow(VisitAcceptanceException::class, 'reward_not_earned');

    expect($action->handle($business->user, 'manual', $pass->manual_code, $id))->toBe($original);
    expect(RewardEntitlement::query()->first()?->getAttributes())->toBe($before);
    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', 1);
})->with(['available entitlement' => 1, 'no entitlement' => 8]);

it('returns a compound replay only from actual original redemption without inferring which request caused it', function () {
    [$business, $pass, $promotion] = visitFixture(['target_points' => 1]);
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->twice()->andReturn(
        new DatabaseTime('2030-01-02 12:00:00+00', '2030-01-02'),
        new DatabaseTime('2030-01-02 12:01:00+00', '2030-01-02'),
    );
    $id = (string) Str::uuid();
    $action = app(AcceptVisit::class);
    $visit = $action->handle($business->user, 'manual', '00007', $id);
    $reward = RewardEntitlement::query()->sole();
    app(RedeemReward::class)->handle($business->user, 'manual', '00007', $reward->id, $promotion->id);

    expect($action->handle($business->user, 'manual', '00007', $id, $promotion->id, true))->toBe($visit);

    expect($reward->fresh()->redeemed_at->toISOString())->not->toBe($visit['confirmed_at']);
    $this->assertDatabaseCount('visits', 1);
    expect($this->http->history)->toBeEmpty();
    Log::shouldHaveReceived('info')->with('reward.redeemed', Mockery::type('array'))->once();
});

it('rechecks compound validity and credentials before new effects or replay disclosure', function (string $case) {
    [$business, $pass, $promotion] = visitFixture(['target_points' => 1]);
    $id = (string) Str::uuid();
    if (in_array($case, ['expired', 'cancelled'], true)) {
        visitClock('2030-01-02 12:00:00+00');
        $promotion->forceFill($case === 'expired'
            ? ['ends_at' => '2030-01-02 12:00:00+00']
            : ['status' => 'cancelled', 'cancelled_at' => '2030-01-02 12:00:00+00'])->save();
    } else {
        visitClock('2030-01-02 12:00:00+00');
        app(AcceptVisit::class)->handle($business->user, 'manual', '00007', $id, $promotion->id, true);
        if ($case === 'retired') {
            $pass->update(['validation_token_hash' => hash('sha256', str_repeat('b', 64))]);
        } else {
            $business = Business::factory()->create();
        }
    }
    $before = RewardEntitlement::query()->get()->map->getAttributes()->all();
    $visitCount = Visit::query()->count();

    expect(fn () => app(AcceptVisit::class)->handle($business->user, 'token', str_repeat('a', 64), $id, $promotion->id, true))
        ->toThrow(VisitAcceptanceException::class, in_array($case, ['expired', 'cancelled'], true) ? 'promotion_unavailable' : 'invalid_credential');

    expect(RewardEntitlement::query()->get()->map->getAttributes()->all())->toBe($before);
    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', $visitCount);
})->with(['expired', 'cancelled', 'retired', 'foreign']);

it('commits compound local facts without a Wallet binding or provider configuration', function () {
    [$business, $pass, $promotion] = visitFixture(['target_points' => 1]);
    $pass->update(['wallet_object_id' => null]);
    visitClock('2030-01-02 12:00:00+00');

    $result = app(AcceptVisit::class)->handle($business->user, 'manual', '00007', (string) Str::uuid(), $promotion->id, true);

    $reward = RewardEntitlement::query()->sole();
    expect($reward->unlocked_at->toISOString())->toBe($result['confirmed_at']);
    expect($reward->redeemed_at?->toISOString())->toBe($result['confirmed_at']);
    expect($reward->redeemed_by_user_id)->toBe($business->user_id);
    expect($pass->fresh()->wallet_object_id)->toBeNull();
    $this->assertDatabaseCount('visits', 1);
    expect($this->http->history)->toBeEmpty();
    Log::shouldHaveReceived('info')->with('reward.redeemed', Mockery::type('array'))->once();
    Log::shouldNotHaveReceived('log');
});

it('correlates acceptance and replay events with the owning Business', function () {
    [$business, $pass, $promotion] = visitFixture();
    visitClock('2030-01-02 12:00:00.123456+00');
    $operationId = (string) Str::uuid();
    $action = app(AcceptVisit::class);

    $accepted = $action->handle($business->user, 'manual', $pass->manual_code, $operationId);
    $stored = Visit::query()->sole()->getAttributes();
    $replayed = $action->handle($business->user, 'manual', $pass->manual_code, $operationId);

    expect($accepted)->toBe([
        'visit_id' => $stored['id'],
        'operation_id' => $operationId,
        'customer_pass_id' => $pass->id,
        'promotion_id' => $promotion->id,
        'points_awarded' => 1,
        'confirmed_at' => '2030-01-02T12:00:00.123456Z',
    ]);
    expect($replayed)->toBe($accepted);
    expect(Visit::query()->sole()->getAttributes())->toBe($stored);
    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 0);
    expect($this->http->history)->toBeEmpty();
    Log::shouldHaveReceived('info')
        ->with('visit.accepted', Mockery::on(fn ($context) => $context['business_id'] === $business->id
            && $context['operation_id'] === $operationId))
        ->once();
    Log::shouldHaveReceived('info')
        ->with('visit.replayed', Mockery::on(fn ($context) => $context['business_id'] === $business->id
            && $context['operation_id'] === $operationId))
        ->once();
    Log::shouldNotHaveReceived('log');
});

it('awards one nonstacking frozen local rule at weekday, touching, microsecond and DST boundaries', function (string $instant, string $timezone, int $weekday, ?string $start, ?string $end, int $multiplier, int $points) {
    [$business, $pass, $promotion] = visitFixture([
        'timezone_snapshot' => $timezone,
        'starts_at' => '2030-01-01 00:00:00+00',
        'ends_at' => '2031-01-01 00:00:00+00',
    ]);
    $promotion->extraPoints()->create([
        'weekday' => $weekday,
        'start_time' => $start,
        'end_time' => $end,
        'multiplier' => $multiplier,
    ]);
    if ($start === '07:00:00') {
        $promotion->extraPoints()->create(['weekday' => $weekday, 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'multiplier' => 5]);
    }
    visitClock($instant);

    $result = app(AcceptVisit::class)->handle($business->user, 'token', str_repeat('a', 64), (string) Str::uuid());

    expect($result['points_awarded'])->toBe($points);
    expect(Visit::query()->sole()->awarded_points)->toBe($points);
    expect($this->http->history)->toBeEmpty();
})->with([
    'x2 whole day' => ['2030-01-02 12:00:00+00', 'America/Bogota', 3, null, null, 2, 2],
    'x3 inclusive start' => ['2030-01-02 12:00:00+00', 'America/Bogota', 3, '07:00:00', '08:00:00', 3, 3],
    'x3 final microsecond' => ['2030-01-02 12:59:59.999999+00', 'America/Bogota', 3, '07:00:00', '08:00:00', 3, 3],
    'touching x5 starts at x3 end' => ['2030-01-02 13:00:00+00', 'America/Bogota', 3, '07:00:00', '08:00:00', 3, 5],
    'outside both windows' => ['2030-01-02 14:00:00+00', 'America/Bogota', 3, '07:00:00', '08:00:00', 3, 1],
    'local previous weekday' => ['2030-01-02 02:00:00+00', 'America/Bogota', 2, null, null, 5, 5],
    'wrong weekday' => ['2030-01-02 12:00:00+00', 'America/Bogota', 2, null, null, 5, 1],
    'spring DST before jump' => ['2030-03-10 06:59:59.999999+00', 'America/New_York', 7, '01:00:00', '02:00:00', 3, 3],
    'spring DST after jump' => ['2030-03-10 07:00:00+00', 'America/New_York', 7, '01:00:00', '02:00:00', 3, 1],
    'autumn first 01:30' => ['2030-11-03 05:30:00+00', 'America/New_York', 7, '01:00:00', '02:00:00', 2, 2],
    'autumn repeated 01:30' => ['2030-11-03 06:30:00+00', 'America/New_York', 7, '01:00:00', '02:00:00', 2, 2],
]);

it('unlocks exactly one entitlement at the Visit instant for exact or overshooting completion', function (int $target, int $points) {
    [$business, $pass, $promotion] = visitFixture(['target_points' => $target]);
    $promotion->extraPoints()->create(['weekday' => 3, 'multiplier' => $points]);
    visitClock('2030-01-02 12:00:00.123456+00');

    $result = app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());

    $reward = RewardEntitlement::query()->sole();
    expect($reward->unlocked_at->toISOString())->toBe($result['confirmed_at']);
    expect($reward->business_id)->toBe($business->id);
    expect($reward->customer_pass_id)->toBe($pass->id);
    expect($reward->promotion_id)->toBe($promotion->id);
    expect($reward->redeemed_at)->toBeNull();
    expect(Visit::query()->sum('awarded_points'))->toBe($points);
    Log::shouldHaveReceived('info')->with('reward.unlocked', Mockery::type('array'))->once();
})->with(['exact' => [3, 3], 'overshoot retains full points' => [4, 5]]);

it('allows intentional same-day Visits and replays the original facts after later Visits and cancellation or expiry', function (string $laterState) {
    [$business, $pass, $promotion] = visitFixture();
    visitClock('2030-01-02 12:00:00.123456+00', 2);
    $action = app(AcceptVisit::class);
    $operationId = (string) Str::uuid();
    $original = $action->handle($business->user, 'manual', $pass->manual_code, $operationId);
    $later = $action->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
    $before = Visit::query()->get()->map->getAttributes()->all();
    if ($laterState === 'cancelled') {
        $promotion->forceFill(['status' => 'cancelled', 'cancelled_at' => '2030-01-03 00:00:00+00'])->save();
    } else {
        $promotion->forceFill(['ends_at' => '2030-01-02 12:00:00+00'])->save();
    }
    $business->update(['timezone' => 'Europe/Madrid']);

    $replay = $action->handle($business->user, 'token', str_repeat('a', 64), strtoupper($operationId));

    expect($replay)->toBe($original);
    expect($later['visit_id'])->not->toBe($original['visit_id']);
    expect(Visit::query()->get()->map->getAttributes()->all())->toBe($before);
    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('reward_entitlements', 0);
    Log::shouldHaveReceived('info')->with('visit.replayed', Mockery::type('array'))->once();
})->with(['cancelled', 'expired']);

it('accepts only the compared current offer while keeping the default caller unchanged', function (bool $matches) {
    [$business, $pass, $promotion] = visitFixture();
    visitClock('2030-01-02 12:00:00+00');
    $before = $pass->fresh()->getAttributes();
    $operationId = (string) Str::uuid();

    if ($matches) {
        $result = app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, $operationId, $promotion->id);
        expect($result['promotion_id'])->toBe($promotion->id);
        expect(array_keys($result))->toBe(['visit_id', 'operation_id', 'customer_pass_id', 'promotion_id', 'points_awarded', 'confirmed_at']);
    } else {
        expect(fn () => app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, $operationId, $promotion->id + 1))
            ->toThrow(VisitAcceptanceException::class, 'promotion_changed');
    }

    expect($pass->fresh()->getAttributes())->toBe($before);
    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', $matches ? 1 : 0);
    $this->assertDatabaseCount('reward_entitlements', 0);
    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(0);
})->with([true, false]);

it('returns authorized original facts before comparing an expected replacement offer', function (string $state) {
    [$business, $pass, $promotion] = visitFixture();
    visitClock('2030-01-02 12:00:00+00');
    $operationId = (string) Str::uuid();
    $action = app(AcceptVisit::class);
    $original = $action->handle($business->user, 'manual', $pass->manual_code, $operationId, $promotion->id);
    $promotion->forceFill($state === 'expired'
        ? ['ends_at' => '2030-01-02 12:00:00+00']
        : ['status' => 'cancelled', 'cancelled_at' => '2030-01-02 12:00:00+00'])->save();
    $replacement = $business->promotions()->make();
    $replacement->forceFill([
        'status' => 'published', 'target_points' => 20, 'reward_title' => 'Otra oferta',
        'timezone_snapshot' => 'America/Bogota', 'starts_at' => '2030-01-02 12:00:00+00', 'ends_at' => '2030-03-01 05:00:00+00',
    ])->save();
    $before = Visit::query()->sole()->getAttributes();

    $replayed = $action->handle($business->user, 'token', str_repeat('a', 64), $operationId, $promotion->id);
    $differentExpected = $action->handle($business->user, 'manual', $pass->manual_code, $operationId, $replacement->id);

    expect($replayed)->toBe($original);
    expect($differentExpected)->toBe($original);
    expect(Visit::query()->sole()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('reward_entitlements', 0);
    expect($this->http->history)->toBeEmpty();
    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(0);
})->with(['cancelled', 'expired']);

it('accepts the existing twelve-character fallback without changing its bytes', function () {
    [$business, $pass] = visitFixture();
    $pass->update(['manual_code' => 'ABCD01234567']);
    $this->http->respondWith([
        'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/'.$pass->wallet_object_id => function (Request $request) use ($business, $pass) {
            return GoogleWalletHttpFake::json([
                'id' => $pass->wallet_object_id,
                'classId' => '123456.business_'.$business->public_id,
                'accountId' => 'ABCD01234567',
                'barcode' => ['type' => 'QR_CODE', 'value' => str_repeat('a', 64)],
                'textModulesData' => $request->data()['textModulesData'] ?? [],
            ]);
        },
    ]);
    visitClock('2030-01-02 12:00:00+00');

    $result = app(AcceptVisit::class)->handle($business->user, 'manual', "\tABCD01234567\n", (string) Str::uuid());

    expect($result['customer_pass_id'])->toBe($pass->id);
    expect($pass->fresh()->manual_code)->toBe('ABCD01234567');
    expect($this->http->history)->toBeEmpty();
});

it('rejects malformed and nonmatching credentials without domain or provider effects', function (string $method, string $credential) {
    [$business, $pass] = visitFixture();
    $before = $pass->fresh()->getAttributes();
    $this->mock(DatabaseClock::class)->shouldNotReceive('capture');

    try {
        app(AcceptVisit::class)->handle($business->user, $method, $credential, (string) Str::uuid());
        test()->fail('Expected credential rejection.');
    } catch (VisitAcceptanceException $failure) {
        expect($failure->failure->value)->toBe('invalid_credential');
    }

    expect($pass->fresh()->getAttributes())->toBe($before);
    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', 0);
    $this->assertDatabaseCount('reward_entitlements', 0);
    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(1);
    Log::shouldHaveReceived('log')->once()->with('notice', 'visit.rejected', Mockery::on(fn ($context) => $context['reason'] === 'invalid_credential'));
    Log::shouldNotHaveReceived('info');
})->with([
    ['manual', '00008'], ['manual', '0007'], ['manual', '００００７'], ['manual', '00 007'],
    ['manual', 'abcd01234567'], ['manual', 'ABCD-1234567'], ['manual', str_repeat('x', 257)],
    ['token', str_repeat('a', 64).' '], ['token', str_repeat('A', 64)], ['token', str_repeat('b', 64)],
    ['scan', str_repeat('a', 64)],
]);

it('requires a persisted verified owning actor before accepting or replaying facts', function (string $actorKind) {
    [$business, $pass] = visitFixture();
    $actor = match ($actorKind) {
        'anonymous' => null,
        'unverified' => User::factory()->unverified()->create(),
        'unsaved' => User::factory()->make(),
        'no business' => User::factory()->create(),
        'foreign business' => Business::factory()->create()->user,
    };

    try {
        app(AcceptVisit::class)->handle($actor, 'token', str_repeat('a', 64), (string) Str::uuid());
        test()->fail('Expected authorization rejection.');
    } catch (VisitAcceptanceException $failure) {
        expect($failure->failure->value)->toBe($actorKind === 'foreign business' ? 'invalid_credential' : 'forbidden');
    }

    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', 0);
    $this->assertDatabaseCount('reward_entitlements', 0);
    Log::shouldHaveReceived('log')->once();
    Log::shouldNotHaveReceived('info');
})->with(['anonymous', 'unverified', 'unsaved', 'no business', 'foreign business']);

it('rejects malformed operation identities without counting them as credential guesses', function (string $operationId) {
    [$business, $pass] = visitFixture();

    expect(fn () => app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, $operationId))
        ->toThrow(VisitAcceptanceException::class, 'invalid_operation');

    expect($this->http->history)->toBeEmpty();
    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(0);
    $this->assertDatabaseCount('visits', 0);
    Log::shouldHaveReceived('log')->once()->with('notice', 'visit.rejected', Mockery::on(fn ($context) => $context['operation_id'] === null));
})->with(['', 'not-a-uuid', '00000000-0000-0000-0000-000000000000'."\n"]);

it('uses the existing invalid-attempt window without charging valid replays or eligibility rejections', function () {
    [$business, $pass] = visitFixture();
    config(['pass_lookup.max_invalid_attempts' => 1]);
    $action = app(AcceptVisit::class);
    expect(fn () => $action->handle($business->user, 'manual', '99999', (string) Str::uuid()))
        ->toThrow(VisitAcceptanceException::class, 'invalid_credential');

    try {
        $action->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
        test()->fail('Expected throttling.');
    } catch (VisitAcceptanceException $failure) {
        expect($failure->failure->value)->toBe('invalid_attempt_limit');
        expect($failure->retryAfterSeconds)->toBeGreaterThan(0);
    }

    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(1);
    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', 0);
});

it('rejects scheduled expired cancelled and draft Promotions using the exclusive operation-time boundary', function (string $state) {
    [$business, $pass, $promotion] = visitFixture();
    $promotion->forceFill(match ($state) {
        'scheduled' => ['starts_at' => '2030-01-02 12:00:00+00'],
        'expired' => ['ends_at' => '2030-01-02 11:59:59+00'],
        'cancelled' => ['status' => 'cancelled', 'cancelled_at' => '2030-01-02 10:00:00+00'],
        'draft' => ['status' => 'draft', 'starts_at' => null, 'ends_at' => null, 'timezone_snapshot' => null, 'local_start_date' => '2030-01-01', 'local_end_date' => '2030-01-31'],
    })->save();
    visitClock('2030-01-02 11:59:59+00');

    expect(fn () => app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid()))
        ->toThrow(VisitAcceptanceException::class, 'promotion_unavailable');

    $this->assertDatabaseCount('visits', 0);
    $this->assertDatabaseCount('reward_entitlements', 0);
    expect($this->http->history)->toBeEmpty();
    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(0);
    Log::shouldHaveReceived('log')->once();
    Log::shouldNotHaveReceived('info');
})->with(['scheduled', 'expired', 'cancelled', 'draft']);

it('accepts the inclusive promotion start without clipping immutable awarded points', function () {
    [$business, $pass] = visitFixture();
    visitClock('2030-01-01 05:00:00+00');

    $result = app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());

    expect($result['confirmed_at'])->toBe('2030-01-01T05:00:00.000000Z');
    expect(Visit::query()->count())->toBe(1);
    expect($this->http->history)->toBeEmpty();
});

it('replays completion but prevents new progress after available or redeemed entitlements', function (bool $redeemed) {
    [$business, $pass] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00+00', 2);
    $action = app(AcceptVisit::class);
    $operationId = (string) Str::uuid();
    $result = $action->handle($business->user, 'manual', $pass->manual_code, $operationId);
    if ($redeemed) {
        RewardEntitlement::query()->sole()->forceFill(['redeemed_at' => '2030-01-02 12:01:00+00', 'redeemed_by_user_id' => $business->user_id])->save();
    }
    $before = RewardEntitlement::query()->sole()->getAttributes();

    expect($action->handle($business->user, 'manual', $pass->manual_code, $operationId))->toBe($result);
    expect(fn () => $action->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid()))
        ->toThrow(VisitAcceptanceException::class, 'promotion_completed');

    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 1);
    expect(RewardEntitlement::query()->sole()->getAttributes())->toBe($before);
    expect($this->http->history)->toBeEmpty();
})->with([false, true]);

it('does not disclose foreign operation facts or authorize a withdrawn credential on replay', function (string $conflict) {
    [$business, $pass] = visitFixture();
    visitClock('2030-01-02 12:00:00+00');
    $operationId = (string) Str::uuid();
    $action = app(AcceptVisit::class);
    $action->handle($business->user, 'token', str_repeat('a', 64), $operationId);
    if ($conflict === 'retired token') {
        $pass->update(['validation_token_hash' => hash('sha256', str_repeat('b', 64))]);
        $credential = str_repeat('a', 64);
    } else {
        $owner = $conflict === 'foreign business' ? Business::factory()->create() : $business;
        $owner->customerPasses()->create(['manual_code' => '12345', 'validation_token_hash' => hash('sha256', str_repeat('c', 64))]);
        $business = $owner;
        $credential = str_repeat('c', 64);
    }
    $before = Visit::query()->sole()->getAttributes();

    expect(fn () => $action->handle($business->user, 'token', $credential, $operationId))
        ->toThrow(VisitAcceptanceException::class, $conflict === 'retired token' ? 'invalid_credential' : 'operation_conflict');

    expect(Visit::query()->sole()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('reward_entitlements', 0);
    expect($this->http->history)->toBeEmpty();
    Log::shouldNotHaveReceived('info', ['visit.replayed', Mockery::any()]);
})->with(['same business different pass', 'foreign business', 'retired token']);

it('rejects ambient transaction ownership before Wallet or mutation', function () {
    [$business, $pass] = visitFixture();

    DB::transaction(function () use ($business, $pass) {
        expect(fn () => app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid()))
            ->toThrow(VisitAcceptanceException::class, 'transaction_active');
    });

    $this->assertDatabaseCount('visits', 0);
    expect($this->http->history)->toBeEmpty();
});

it('commits Visit and Reward facts without contacting a rejecting or unavailable provider', function (int $status, bool $combined) {
    [$business, $pass, $promotion] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00+00');
    $this->http->respondWith(fn () => GoogleWalletHttpFake::json(['error' => 'unavailable'], $status));

    $result = app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid(), $promotion->id, $combined);

    $this->assertDatabaseCount('visits', 1);
    $reward = RewardEntitlement::query()->sole();
    expect($reward->unlocked_at->toISOString())->toBe($result['confirmed_at']);
    expect($reward->redeemed_at !== null)->toBe($combined);
    expect($this->http->history)->toBeEmpty();
    Log::shouldNotHaveReceived('log');
})->with(['provider rejection' => 400, 'provider outage' => 503])->with([false, true]);

it('rejects a model-cancelled Visit or entitlement save instead of reporting success', function (string $model, bool $combined) {
    [$business, $pass] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00+00');
    $dispatcher = Model::getEventDispatcher();
    Model::setEventDispatcher(clone $dispatcher);
    $modelClass = $model === 'visit' ? Visit::class : RewardEntitlement::class;
    $modelClass::saving(fn () => false);

    try {
        expect(fn () => app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid(), null, $combined))
            ->toThrow(VisitAcceptanceException::class, 'save_rejected');
    } finally {
        Model::setEventDispatcher($dispatcher);
    }

    $this->assertDatabaseCount('visits', 0);
    $this->assertDatabaseCount('reward_entitlements', 0);
    expect($this->http->history)->toBeEmpty();
    Log::shouldHaveReceived('log')->once();
    Log::shouldNotHaveReceived('info');
})->with(['visit', 'reward'])->with([false, true]);

it('sanitizes an actual PostgreSQL constraint rejection and rolls back without provider work', function () {
    [$business, $pass] = visitFixture();
    visitClock('2030-01-02 12:00:00+00');
    $dispatcher = Model::getEventDispatcher();
    Model::setEventDispatcher(clone $dispatcher);
    Visit::saving(function (Visit $visit) {
        $visit->awarded_points = 0;
    });

    try {
        app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
        test()->fail('Expected native storage rejection.');
    } catch (VisitAcceptanceException $failure) {
        expect($failure->failure->value)->toBe('database_failed');
        expect($failure->context['previous_class'])->toBe(QueryException::class);
        expect($failure->context['sqlstate'])->toBe('23514');
        expect($failure->getPrevious())->toBeNull();
        expect($failure->localOutcome)->toBe(FailureOutcome::Definitive);
    } finally {
        Model::setEventDispatcher($dispatcher);
    }

    $this->assertDatabaseCount('visits', 0);
    $this->assertDatabaseCount('reward_entitlements', 0);
    expect($this->http->history)->toBeEmpty();
    Log::shouldHaveReceived('log')->once();
    Log::shouldNotHaveReceived('info');
});

it('does not publish success or retry when local commit completion is unresolved', function (bool $commitHappened, bool $combined) {
    [$business, $pass, $promotion] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00+00', 3);
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $observer = clone $dispatcher;
    $observer->listen($commitHappened ? TransactionCommitted::class : TransactionCommitting::class, function () {
        throw new PDOException('private commit payload');
    });
    $connection->setEventDispatcher($observer);
    $id = (string) Str::uuid();

    try {
        app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, $id, null, $combined);
        test()->fail('Expected unresolved local completion.');
    } catch (VisitAcceptanceException $failure) {
        expect($failure->localOutcome)->toBe(FailureOutcome::Unknown);

        expect($failure->getPrevious())->toBeNull();
    } finally {
        $connection->setEventDispatcher($dispatcher);
        if (! $commitHappened) {
            DB::disconnect();
        }
    }

    $receipt = app(VisitReceipt::class)->find($business->user, 'manual', $pass->manual_code, $id);
    $reward = app(RewardRedemptionReceipt::class)->find($business->user, 'manual', $pass->manual_code, $promotion->id);
    expect($receipt !== null)->toBe($commitHappened);
    expect(($reward['redeemed_at'] ?? null) !== null)->toBe($commitHappened && $combined);
    if ($commitHappened) {
        expect($receipt['operation_id'])->toBe($id);
        expect($reward['promotion_id'])->toBe($promotion->id);
    }
    $this->assertDatabaseCount('visits', $commitHappened ? 1 : 0);
    $this->assertDatabaseCount('reward_entitlements', $commitHappened ? 1 : 0);
    expect($this->http->history)->toBeEmpty();
    Log::shouldHaveReceived('log')->once()->with('error', 'visit.acceptance_failed', Mockery::on(
        fn ($context) => ! array_key_exists('provider_confirmed', $context) && $context['local_outcome'] === 'unknown'
            && ! str_contains(json_encode($context), 'private commit payload'),
    ));
    Log::shouldNotHaveReceived('info');
})->with(['failed commit' => false, 'lost completion after real commit' => true])->with([false, true]);

it('keeps tentative facts invisible to another connection and logs only after the outer commit', function () {
    [$business, $pass] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00+00');
    config(['database.connections.visit_observer' => config('database.connections.pgsql')]);
    $observer = DB::connection('visit_observer');
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $events = clone $dispatcher;
    $events->listen(TransactionCommitting::class, function () use ($observer) {
        expect(Visit::query()->count())->toBe(1);
        expect(RewardEntitlement::query()->count())->toBe(1);
        expect($observer->table('visits')->count())->toBe(0);
        expect($observer->table('reward_entitlements')->count())->toBe(0);
        Log::shouldNotHaveReceived('info');
    });
    $connection->setEventDispatcher($events);
    Log::shouldReceive('info')->with('visit.accepted', Mockery::on(function ($context) use ($observer) {
        expect(DB::transactionLevel())->toBe(0);
        expect($observer->table('visits')->count())->toBe(1);
        expect($observer->table('reward_entitlements')->count())->toBe(1);

        return isset($context['confirmed_at']) && ! str_contains(json_encode($context), '00007');
    }))->once();

    try {
        $result = app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
        expect($result['points_awarded'])->toBe(1);
        expect($this->http->history)->toBeEmpty();
        Log::shouldNotHaveReceived('log');
    } finally {
        $connection->setEventDispatcher($dispatcher);
        DB::purge('visit_observer');
    }
});

it('sanitizes a native model source failure and propagates native failure-logger errors unchanged', function () {
    [$business, $pass] = visitFixture();
    visitClock('2030-01-02 12:00:00+00');
    $dispatcher = Model::getEventDispatcher();
    Model::setEventDispatcher(clone $dispatcher);
    Visit::saving(function () {
        throw new RuntimeException('private-model-credential');
    });
    $loggerFailure = new RuntimeException('logger failed');
    Log::shouldReceive('log')->with('error', 'visit.acceptance_failed', Mockery::on(
        fn ($context) => $context['reason'] === 'unexpected_failure'
            && $context['previous_class'] === RuntimeException::class
            && ! str_contains(json_encode($context), 'private-model-credential'),
    ))->once()->andThrow($loggerFailure);

    try {
        app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
        test()->fail('Expected native logging failure.');
    } catch (RuntimeException $failure) {
        expect($failure)->toBe($loggerFailure);
    } finally {
        Model::setEventDispatcher($dispatcher);
    }

    $this->assertDatabaseCount('visits', 0);
    $this->assertDatabaseCount('reward_entitlements', 0);
    expect($this->http->history)->toBeEmpty();
    Log::shouldNotHaveReceived('info');
});

it('preserves programmer contract errors for the configured unexpected reporter instead of classifying them as rejections', function () {
    [$business, $pass] = visitFixture();
    visitClock('2030-01-02 12:00:00+00');
    $dispatcher = Model::getEventDispatcher();
    Model::setEventDispatcher(clone $dispatcher);
    $native = new LogicException('model contract violated');
    Visit::saving(function () use ($native) {
        throw $native;
    });

    try {
        app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
        test()->fail('Expected original contract failure.');
    } catch (LogicException $failure) {
        expect($failure)->toBe($native);
    } finally {
        Model::setEventDispatcher($dispatcher);
    }

    $this->assertDatabaseCount('visits', 0);
    expect($this->http->history)->toBeEmpty();
    Log::shouldNotHaveReceived('log');
    Log::shouldNotHaveReceived('info');
});

it('accepts local facts when Google configuration cannot be loaded', function () {
    [$business, $pass] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00+00');
    $this->mock(GoogleWalletConfiguration::class)->shouldReceive('load')->never()->andThrow(new RuntimeException('configuration unavailable'));

    app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());

    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 1);
    expect($this->http->history)->toBeEmpty();
    Log::shouldHaveReceived('info')->with('visit.accepted', Mockery::type('array'))->once();
    Log::shouldNotHaveReceived('log');
});

it('propagates a failed completion logger unchanged without misclassifying committed facts or repeating Google', function () {
    [$business, $pass] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00+00');
    $native = new RuntimeException('logger unavailable');
    Log::shouldReceive('info')->once()->with('visit.accepted', Mockery::type('array'))->andThrow($native);

    try {
        app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
        test()->fail('Expected native logger failure.');
    } catch (RuntimeException $failure) {
        expect($failure)->toBe($native);
    }

    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 1);
    expect($this->http->history)->toBeEmpty();
    Log::shouldNotHaveReceived('log');
});

it('redeems the original Reward once and replays immutable facts after expiry or cancellation without another provider call', function (string $state) {
    [$business, $pass, $promotion] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00.123456+00', 2);
    app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
    $reward = RewardEntitlement::query()->sole();
    $passBefore = $pass->fresh()->getAttributes();
    $visitsBefore = Visit::query()->sole()->getAttributes();

    $action = app(RedeemReward::class);
    $result = $action->handle($business->user, 'manual', ' 00007 ', $reward->id, $promotion->id);
    $stored = $reward->fresh()->getAttributes();
    $promotion->forceFill($state === 'expired'
        ? ['ends_at' => '2030-01-02 12:00:00+00']
        : ['status' => 'cancelled', 'cancelled_at' => '2030-01-03 00:00:00+00'])->save();
    $replay = $action->handle($business->user, 'token', str_repeat('a', 64), $reward->id, $promotion->id);

    expect($result)->toBe([
        'reward_entitlement_id' => $reward->id,
        'customer_pass_id' => $pass->id,
        'promotion_id' => $promotion->id,
        'redeemed_at' => '2030-01-02T12:00:00.123456Z',
        'redeemed_by_user_id' => $business->user_id,
    ]);
    expect($replay)->toBe($result);
    expect($reward->fresh()->getAttributes())->toBe($stored);
    expect($pass->fresh()->getAttributes())->toBe($passBefore);
    expect(Visit::query()->sole()->getAttributes())->toBe($visitsBefore);
    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('reward_entitlements', 1);
    Log::shouldHaveReceived('info')->with('reward.redeemed', Mockery::type('array'))->once();
    Log::shouldHaveReceived('info')->with('reward.redemption_replayed', Mockery::type('array'))->once();
})->with(['expired', 'cancelled']);

it('rejects new Reward redemption at the exclusive end or after cancellation without changing facts or calling Google', function (string $state) {
    [$business, $pass, $promotion] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00+00', 2);
    app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
    $reward = RewardEntitlement::query()->sole();
    $promotion->forceFill($state === 'expired'
        ? ['ends_at' => '2030-01-02 12:00:00+00']
        : ['status' => 'cancelled', 'cancelled_at' => '2030-01-02 12:00:00+00'])->save();
    $before = $reward->fresh()->getAttributes();

    expect(fn () => app(RedeemReward::class)->handle($business->user, 'manual', $pass->manual_code, $reward->id, $promotion->id))
        ->toThrow(RewardRedemptionException::class, $state);

    expect($reward->fresh()->getAttributes())->toBe($before);
    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', 1);
    Log::shouldHaveReceived('log')->once();
})->with(['expired', 'cancelled']);

it('revalidates Reward credentials and original target before returning even redeemed facts', function (string $case) {
    [$business, $pass, $promotion] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00+00', 2);
    app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
    $reward = RewardEntitlement::query()->sole();
    $action = app(RedeemReward::class);
    $action->handle($business->user, 'manual', $pass->manual_code, $reward->id, $promotion->id);
    $actor = $business->user;
    $method = 'manual';
    $credential = $pass->manual_code;
    $target = $promotion->id;
    if ($case === 'retired') {
        $pass->update(['validation_token_hash' => hash('sha256', str_repeat('b', 64))]);
        $method = 'token';
        $credential = str_repeat('a', 64);
    } elseif ($case === 'foreign') {
        $actor = Business::factory()->create()->user;
    } elseif ($case === 'original') {
        $target++;
    } else {
        $actor = null;
    }
    $before = $reward->fresh()->getAttributes();

    expect(fn () => $action->handle($actor, $method, $credential, $reward->id, $target))
        ->toThrow(RewardRedemptionException::class);

    expect($reward->fresh()->getAttributes())->toBe($before);
    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 1);
})->with(['retired', 'foreign', 'original', 'guest']);

it('redeems locally without contacting an unavailable provider', function () {
    [$business, $pass, $promotion] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00+00', 2);
    app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
    $reward = RewardEntitlement::query()->sole();
    $pass->update(['wallet_object_id' => null]);
    $this->http->respondWith(fn () => GoogleWalletHttpFake::json(['error' => 'unavailable'], 503));

    $result = app(RedeemReward::class)->handle($business->user, 'manual', $pass->manual_code, $reward->id, $promotion->id);

    expect($reward->fresh()->redeemed_at?->toISOString())->toBe($result['redeemed_at']);
    expect($reward->fresh()->redeemed_by_user_id)->toBe($business->user_id);
    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 1);
    Log::shouldHaveReceived('info')->with('reward.redeemed', Mockery::type('array'))->once();
    Log::shouldNotHaveReceived('log');
});

it('sanitizes a real redemption constraint failure once and preserves every previously committed fact', function () {
    [$business, $pass, $promotion] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00+00', 2);
    app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
    $reward = RewardEntitlement::query()->sole();
    $before = $reward->getAttributes();
    $dispatcher = Model::getEventDispatcher();
    Model::setEventDispatcher(clone $dispatcher);
    RewardEntitlement::saving(function (RewardEntitlement $reward) {
        $reward->setAttribute('redeemed_at', null);
    });

    try {
        app(RedeemReward::class)->handle($business->user, 'manual', $pass->manual_code, $reward->id, $promotion->id);
        test()->fail('Expected a paired-stamp constraint rejection.');
    } catch (RewardRedemptionException $failure) {
        expect($failure->failure->value)->toBe('database_failed');
        expect($failure->getPrevious())->toBeNull();
        expect($failure->context['sqlstate'])->toBe('23514');
        expect($failure->localOutcome)->toBe(FailureOutcome::Definitive);
    } finally {
        Model::setEventDispatcher($dispatcher);
    }

    expect($reward->fresh()->getAttributes())->toBe($before);
    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', 1);
    Log::shouldHaveReceived('log')->once()->with('error', 'reward.redemption_failed', Mockery::on(
        fn ($context) => $context['reason'] === 'database_failed'
            && ! str_contains(json_encode($context), '00007') && ! str_contains(json_encode($context), 'update "reward_entitlements"'),
    ));
});

it('preserves local redemption commit uncertainty and reconciles read-only', function (bool $committed) {
    [$business, $pass, $promotion] = visitFixture(['target_points' => 1]);
    visitClock('2030-01-02 12:00:00+00', 3);
    app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
    $reward = RewardEntitlement::query()->sole();
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $observer = clone $dispatcher;
    $observer->listen($committed ? TransactionCommitted::class : TransactionCommitting::class, function () {
        throw new PDOException('private redemption commit payload');
    });
    $connection->setEventDispatcher($observer);
    try {
        app(RedeemReward::class)->handle($business->user, 'manual', $pass->manual_code, $reward->id, $promotion->id);
        test()->fail('Expected uncertain completion.');
    } catch (RewardRedemptionException $failure) {
        expect($failure->localOutcome)->toBe(FailureOutcome::Unknown);
        expect($failure->getPrevious())->toBeNull();
    } finally {
        $connection->setEventDispatcher($dispatcher);
        if (! $committed) {
            DB::disconnect();
        }
    }

    $receipt = app(RewardRedemptionReceipt::class)->find($business->user, 'manual', $pass->manual_code, $promotion->id, $reward->id);
    expect($receipt['redeemed_at'] !== null)->toBe($committed);
    expect($receipt['redeemed_by_user_id'])->toBe($committed ? $business->user_id : null);
    expect($this->http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 1);
    Log::shouldHaveReceived('log')->once()->with('error', 'reward.redemption_failed', Mockery::on(
        fn ($context) => ! array_key_exists('provider_confirmed', $context) && $context['local_outcome'] === 'unknown' && ! str_contains(json_encode($context), 'private redemption commit payload'),
    ));
})->with([false, true]);

it('serializes concurrent Reward replay and captures the clock only after a real pass-lock wait', function (bool $expire, bool $combined) {
    [$business, $pass, $promotion] = visitFixture([
        'target_points' => 1, 'starts_at' => '2000-01-01 00:00:00+00', 'ends_at' => '2100-01-01 00:00:00+00',
    ]);
    $operationId = $combined ? (string) Str::uuid() : null;
    if (! $combined) {
        app(AcceptVisit::class)->handle($business->user, 'manual', $pass->manual_code, (string) Str::uuid());
    }
    $reward = RewardEntitlement::query()->first();
    $before = $reward?->getAttributes();
    $end = $expire ? DB::scalar("SELECT clock_timestamp() + interval '4 seconds'") : null;
    if ($expire) {
        $promotion->forceFill(['ends_at' => $end])->save();
    }
    $processes = [redemptionWorker($business->user_id, $reward?->id ?? 0, $promotion->id, $operationId)];
    if (! $expire) {
        $processes[] = redemptionWorker($business->user_id, $reward?->id ?? 0, $promotion->id, $operationId);
    }
    DB::beginTransaction();
    $pass->newQuery()->whereKey($pass->id)->lockForUpdate()->firstOrFail();

    try {
        foreach ($processes as $process) {
            $process->start();
        }
        $deadline = hrtime(true) + 6_000_000_000;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = 0;
            foreach ($processes as $process) {
                $first = json_decode(explode(PHP_EOL, $process->getOutput())[0], true);
                if (isset($first['backend_id'])) {
                    $waiting += (int) (DB::scalar('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$first['backend_id']]) === 'Lock');
                }
            }
            if ($waiting === count($processes)) {
                break;
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);
        expect($waiting)->toBe(count($processes));
        if ($expire) {
            expect((bool) DB::scalar('SELECT clock_timestamp() < ?::timestamptz', [$end]))->toBeTrue();
            while ((bool) DB::scalar('SELECT clock_timestamp() < ?::timestamptz', [$end])) {
                if (hrtime(true) >= $deadline) {
                    test()->fail('PostgreSQL did not reach the fixture deadline.');
                }
                usleep(10_000);
            }
        }
        DB::commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getErrorOutput())->toBe('');
            expect($process->getExitCode())->toBe(0);
            $lines = array_filter(explode(PHP_EOL, trim($process->getOutput())));
            $results[] = json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
        }

        expect(array_sum(array_column($results, 'provider_calls')))->toBe(0);
        if ($expire) {
            expect($results[0]['failure'])->toBe($combined ? 'promotion_unavailable' : 'expired');
            expect($results[0]['result'])->toBeNull();
            expect($reward?->fresh()->getAttributes())->toBe($before);
        } else {
            expect(array_column($results, 'failure'))->toBe([null, null]);
            expect($results[0]['result'])->toBe($results[1]['result']);
            $stored = RewardEntitlement::query()->sole();
            expect($stored->redeemed_by_user_id)->toBe($business->user_id);
            expect($stored->redeemed_at->toISOString())->toBe($results[0]['result'][$combined ? 'confirmed_at' : 'redeemed_at']);
        }
        $this->assertDatabaseCount('visits', $combined && $expire ? 0 : 1);
        $this->assertDatabaseCount('reward_entitlements', $combined && $expire ? 0 : 1);
        expect($this->http->history)->toBeEmpty();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
})->with(['concurrent replay' => false, 'exclusive end crossed while locked' => true])->with([false, true]);

/**
 * Creates a guarded separate process using the real redemption Action and PostgreSQL locks.
 *
 * @param  int  $actorId  Committed owner.
 * @param  int  $rewardId  Original entitlement.
 * @param  int  $promotionId  Original Promotion.
 * @param  string|null  $operationId  Explicit compound Visit identity, or null for standalone redemption.
 * @return Process Worker owned and awaited by the foreground test.
 */
function redemptionWorker(int $actorId, int $rewardId, int $promotionId, ?string $operationId = null): Process
{
    $worker = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (!app()->environment('testing') || DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'testing' || DB::scalar('SELECT current_database()') !== 'testing') {
            fwrite(STDERR, "Refusing non-testing database.\n");
            exit(2);
        }
        config(['logging.default' => 'null', 'cache.default' => 'array']);
        $calls = 0;
        $wallet = Mockery::mock(App\Integrations\GoogleWallet\GoogleWalletPassUpdater::class);
        $wallet->shouldReceive('update')->andReturnUsing(function () use (&$calls) { $calls++; });
        app()->instance(App\Integrations\GoogleWallet\GoogleWalletPassUpdater::class, $wallet);
        $actor = App\Models\User::query()->findOrFail((int) $argv[1]);
        fwrite(STDOUT, json_encode(['backend_id' => (int) DB::scalar('SELECT pg_backend_pid()')]).PHP_EOL);
        fflush(STDOUT);
        $result = null;
        $failure = null;
        try {
            $result = $argv[4] !== ''
                ? app(App\Actions\Visits\AcceptVisit::class)->handle($actor, 'manual', '00007', $argv[4], (int) $argv[3], true)
                : app(App\Actions\Rewards\RedeemReward::class)->handle($actor, 'manual', '00007', (int) $argv[2], (int) $argv[3]);
        } catch (App\Exceptions\RewardRedemptionException|App\Exceptions\VisitAcceptanceException $exception) {
            $failure = $exception->failure->value;
        }
        fwrite(STDOUT, json_encode(['result' => $result, 'failure' => $failure, 'provider_calls' => $calls], JSON_THROW_ON_ERROR).PHP_EOL);
        PHP;

    return new Process([PHP_BINARY, '-r', $worker, (string) $actorId, (string) $rewardId, (string) $promotionId, $operationId ?? ''], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'testing', 'DB_URL' => '',
        'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'),
        'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'),
    ], null, 12);
}

/**
 * Creates a pass and frozen Promotion for one local commercial command test.
 *
 * @param  array<string, mixed>  $terms  Overrides for frozen Promotion terms.
 * @return array{Business, CustomerPass, Promotion} Persisted same-Business fixtures.
 */
function visitFixture(array $terms = []): array
{
    $business = Business::factory()->create(['timezone' => 'Pacific/Auckland', 'pass_background_color' => '#123456']);
    RateLimiter::clear('pass-lookup:'.$business->user_id);
    $pass = $business->customerPasses()->create([
        'manual_code' => '00007',
        'validation_token_hash' => hash('sha256', str_repeat('a', 64)),
    ]);
    $pass->update(['wallet_object_id' => '123456.pass_'.$pass->public_id]);
    $promotion = $business->promotions()->make();
    $promotion->forceFill(array_replace([
        'status' => 'published',
        'target_points' => 8,
        'reward_title' => 'Un pan artesanal',
        'timezone_snapshot' => 'America/Bogota',
        'starts_at' => '2030-01-01 05:00:00+00',
        'ends_at' => '2030-02-01 05:00:00+00',
    ], $terms))->save();

    return [$business, $pass, $promotion];
}

/**
 * Supplies one controlled operation instant while leaving PostgreSQL calendar evaluation real.
 *
 * @param  string  $instant  UTC operation instant.
 * @param  int  $captures  Expected number of new mutations.
 */
function visitClock(string $instant, int $captures = 1): void
{
    test()->mock(DatabaseClock::class)->shouldReceive('capture')->times($captures)
        ->andReturn(new DatabaseTime($instant, substr($instant, 0, 10)));
}
