<?php

use App\Data\Time\DatabaseTime;
use App\Integrations\GoogleWallet\GoogleWalletPassCreator;
use App\Integrations\GoogleWallet\GoogleWalletPassUpdater;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Models\User;
use App\Support\BusinessSummary;
use App\Support\CustomerPassLookup;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['pass_lookup.max_invalid_attempts' => 3, 'pass_lookup.decay_seconds' => 60]);
});

it('identifies the same own-Business pass with token and normalized manual credentials without writes', function (string $type, string $value, string $storedCode) {
    $pass = lookupFixturePass($storedCode);
    $this->actingAs($pass->business->user);
    $this->mock(GoogleWalletPassCreator::class)->shouldNotReceive('create');
    $this->mock(GoogleWalletPassUpdater::class)->shouldNotReceive('update');
    $this->mock(BusinessSummary::class)->shouldNotReceive('read');
    $before = lookupDomainSnapshot();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $first = app(CustomerPassLookup::class)->lookup($type, $value);
    $second = app(CustomerPassLookup::class)->lookup($type, $value);

    expect($first['status'])->toBe('found');
    expect($first['customer_pass_id'])->toBe($pass->id);
    expect($first['promotion'])->toBeNull();
    expect($first['can_accept_visit'])->toBeFalse();
    expect($first['can_redeem_reward'])->toBeFalse();
    expect(array_keys($first))->toBe([
        'status', 'customer_pass_id', 'checked_at', 'promotion', 'can_accept_visit', 'can_redeem_reward',
    ]);
    expect(array_diff_key($first, ['checked_at' => true]))->toBe(array_diff_key($second, ['checked_at' => true]));
    expect(lookupDomainSnapshot())->toBe($before);
    expect($writes)->toBeEmpty();
    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(0);
})->with([
    'opaque scan token' => ['token', str_repeat('a', 64), '00007'],
    'leading zeros' => ['manual', '00-0 07', '00007'],
    'minimum manual code' => ['manual', '00000', '00000'],
    'maximum manual code' => ['manual', '99999', '99999'],
    'legacy normalized code' => ['manual', 'ab12-cd34 ef56', 'AB12CD34EF56'],
]);

it('rejects unauthorized actors without consuming credential attempts or exposing state', function (string $actor) {
    $pass = lookupFixturePass();
    if ($actor === 'unverified') {
        $pass->business->user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($pass->business->user);
    } elseif ($actor === 'without Business') {
        $this->actingAs(User::factory()->create());
    }
    $before = lookupDomainSnapshot();

    $result = app(CustomerPassLookup::class)->lookup('token', str_repeat('a', 64));

    expect($result)->toBe(['status' => 'forbidden']);
    expect(lookupDomainSnapshot())->toBe($before);
    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(0);
})->with(['anonymous', 'unverified', 'without Business']);

it('never reveals a foreign token while duplicate manual codes remain Business scoped', function () {
    $own = lookupFixturePass();
    $foreign = lookupFixturePass('00007', str_repeat('b', 64));
    $this->actingAs($own->business->user);
    $before = lookupDomainSnapshot();

    $rejected = app(CustomerPassLookup::class)->lookup('token', str_repeat('b', 64));
    $found = app(CustomerPassLookup::class)->lookup('manual', '00007');

    expect($rejected)->toBe(['status' => 'invalid']);
    expect($found['customer_pass_id'])->toBe($own->id)->not->toBe($foreign->id);
    expect(lookupDomainSnapshot())->toBe($before);
    expect(RateLimiter::attempts('pass-lookup:'.$own->business->user_id))->toBe(1);
});

it('rejects invalid or public identifiers uniformly without leaking them', function (string $type, string $value) {
    $pass = lookupFixturePass();
    $this->actingAs($pass->business->user);
    $before = lookupDomainSnapshot();
    Log::spy();

    $result = app(CustomerPassLookup::class)->lookup($type, $value);

    expect($result)->toBe(['status' => 'invalid']);
    expect(lookupDomainSnapshot())->toBe($before);
    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(1);
    Log::shouldHaveReceived('notice')->once()->with('customer_pass.lookup_rejected', [
        'actor_id' => $pass->business->user_id,
        'business_id' => $pass->business_id,
        'outcome' => 'rejected',
        'reason' => 'invalid_credential',
    ]);
})->with([
    'public UUID is not a private token' => ['token', '019b76da-a800-7000-8000-000000000001'],
    'public join URL' => ['token', 'https://example.test/join/019b76da-a800-7000-8000-000000000001'],
    'unknown credential type' => ['public_id', '019b76da-a800-7000-8000-000000000001'],
    'SQL input' => ['manual', "' OR 1=1 --"],
    'empty code' => ['manual', ''],
    'unicode digits' => ['manual', '１２３４５'],
    'short legacy code' => ['manual', 'AB12CD'],
    'missing private token' => ['token', str_repeat('f', 64)],
    'oversized input' => ['manual', str_repeat('0', 257)],
]);

it('temporarily restricts rotating invalid credentials across both input methods without adding blocked attempts', function () {
    $pass = lookupFixturePass();
    $this->actingAs($pass->business->user);
    $before = lookupDomainSnapshot();
    Log::spy();

    foreach (['00001', '00002', '00003'] as $code) {
        expect(app(CustomerPassLookup::class)->lookup('manual', $code))->toBe(['status' => 'invalid']);
    }
    $blocked = app(CustomerPassLookup::class)->lookup('token', str_repeat('a', 64));

    expect($blocked['status'])->toBe('throttled');
    expect($blocked['retry_after'])->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    expect(array_keys($blocked))->toBe(['status', 'retry_after']);
    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(3);
    expect(lookupDomainSnapshot())->toBe($before);
    Log::shouldHaveReceived('notice')->with('customer_pass.lookup_throttled', [
        'actor_id' => $pass->business->user_id,
        'business_id' => $pass->business_id,
        'outcome' => 'rejected',
        'reason' => 'invalid_attempt_limit',
    ])->once();

    $this->travel(61)->seconds();
    expect(app(CustomerPassLookup::class)->lookup('manual', '00007')['status'])->toBe('found');
    $this->travelBack();
});

it('derives eligibility from one database-time hint and rejects inactive boundaries', function (string $phase, string $start, string $end, bool $active) {
    $pass = lookupFixturePass();
    $this->actingAs($pass->business->user);
    $promotion = lookupFixturePromotion($pass->business, $phase, $start, $end);
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()->with($pass->business->timezone)
        ->andReturn(new DatabaseTime('2030-01-02 12:00:00+00', '2030-01-02'));
    $before = lookupDomainSnapshot();

    $result = app(CustomerPassLookup::class)->lookup('manual', '00007');

    expect($result['status'])->toBe('found');
    expect($result['checked_at'])->toBe('2030-01-02 12:00:00+00');
    expect($result['can_accept_visit'])->toBe($active);
    expect($result['can_redeem_reward'])->toBeFalse();
    expect($result['promotion']['id'] ?? null)->toBe($active ? $promotion->id : null);
    expect(lookupDomainSnapshot())->toBe($before);
})->with([
    'inclusive start' => ['published', '2030-01-02 12:00:00+00', '2030-01-03 12:00:00+00', true],
    'exclusive end' => ['published', '2030-01-01 12:00:00+00', '2030-01-02 12:00:00+00', false],
    'scheduled' => ['published', '2030-01-03 12:00:00+00', '2030-01-04 12:00:00+00', false],
    'ended' => ['published', '2030-01-01 12:00:00+00', '2030-01-02 11:59:59+00', false],
    'cancelled' => ['cancelled', '2030-01-01 12:00:00+00', '2030-01-03 12:00:00+00', false],
    'draft' => ['draft', '2030-01-01 12:00:00+00', '2030-01-03 12:00:00+00', false],
]);

it('uses actual PostgreSQL time despite an incorrect application clock and unavailable Summary reader', function () {
    $pass = lookupFixturePass();
    $this->actingAs($pass->business->user);
    $instant = DB::selectOne('SELECT clock_timestamp() AS instant')->instant;
    $time = CarbonImmutable::parse($instant);
    lookupFixturePromotion($pass->business, 'published', $time->subHour()->toIso8601String(), $time->addHour()->toIso8601String());
    $this->travelTo('2040-01-01');
    $this->mock(BusinessSummary::class)->shouldNotReceive('read');
    $before = lookupDomainSnapshot();

    $result = app(CustomerPassLookup::class)->lookup('manual', '00007');

    expect($result['can_accept_visit'])->toBeTrue();
    expect(CarbonImmutable::parse($result['checked_at'])->year)->toBe($time->year);
    expect(lookupDomainSnapshot())->toBe($before);
    $this->travelBack();
});

it('reports immutable awarded points and existing Reward facts without granting or redeeming anything', function (int $points, string $reward, bool $visit, bool $redeem) {
    $pass = lookupFixturePass();
    $this->actingAs($pass->business->user);
    $promotion = lookupFixturePromotion($pass->business, 'published', '2030-01-01 12:00:00+00', '2030-01-03 12:00:00+00');
    $ownership = ['business_id' => $pass->business_id, 'customer_pass_id' => $pass->id, 'promotion_id' => $promotion->id];
    DB::table('visits')->insert($ownership + [
        'awarded_points' => $points,
        'operation_id' => (string) Str::uuid(),
        'confirmed_by_user_id' => $pass->business->user_id,
        'confirmed_at' => '2030-01-02 10:00:00+00',
    ]);
    if ($reward !== 'none') {
        DB::table('reward_entitlements')->insert($ownership + [
            'unlocked_at' => '2030-01-02 10:00:00+00',
            'redeemed_at' => $reward === 'redeemed' ? '2030-01-02 11:00:00+00' : null,
            'redeemed_by_user_id' => $reward === 'redeemed' ? $pass->business->user_id : null,
        ]);
    }
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()
        ->andReturn(new DatabaseTime('2030-01-02 12:00:00+00', '2030-01-02'));
    $before = lookupDomainSnapshot();

    $result = app(CustomerPassLookup::class)->lookup('manual', '00007');

    expect($result['promotion'])->toBe([
        'id' => $promotion->id, 'target_points' => 8, 'earned_points' => $points,
        'reward_title' => 'Un premio', 'reward_status' => $reward,
    ]);
    expect($result['can_accept_visit'])->toBe($visit);
    expect($result['can_redeem_reward'])->toBe($redeem);
    expect(lookupDomainSnapshot())->toBe($before);
})->with([
    'below goal' => [3, 'none', true, false],
    'completion does not grant missing entitlement' => [8, 'none', false, false],
    'available reward' => [8, 'available', false, true],
    'redeemed reward' => [8, 'redeemed', false, false],
]);

it('fails closed with sanitized diagnostics when the authoritative clock read fails', function () {
    $pass = lookupFixturePass();
    $this->actingAs($pass->business->user);
    $before = lookupDomainSnapshot();
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()->andThrow(
        new QueryException('pgsql', 'select secret', ['00007'], new PDOException('private provider error')),
    );
    Log::spy();

    $result = app(CustomerPassLookup::class)->lookup('manual', '00007');

    expect($result)->toBe(['status' => 'unavailable']);
    expect(lookupDomainSnapshot())->toBe($before);
    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(0);
    Log::shouldHaveReceived('error')->once()->with('customer_pass.lookup_unavailable', Mockery::on(
        fn (array $context): bool => ! str_contains(json_encode($context), '00007')
            && ! str_contains(json_encode($context), 'private provider error')
            && $context['reason'] === 'authoritative_read_failed',
    ));
});

/**
 * Creates an issued credential fixture with its own verified Business owner.
 *
 * @param  string  $code  Business-scoped manual identifier.
 * @param  string  $token  Synthetic private scan value.
 * @return CustomerPass Persisted identity without provider calls.
 */
function lookupFixturePass(string $code = '00007', string $token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): CustomerPass
{
    return CustomerPass::factory()->create([
        'manual_code' => $code,
        'validation_token_hash' => hash('sha256', $token),
        'wallet_object_id' => 'synthetic.'.Str::uuid(),
    ]);
}

/**
 * Stores one Promotion in a selected phase without invoking publication or Wallet operations.
 *
 * @param  Business  $business  Owning tenant.
 * @param  string  $status  Stored lifecycle status.
 * @param  string  $start  Inclusive timestamp.
 * @param  string  $end  Exclusive timestamp.
 * @return Promotion Persisted fixture.
 */
function lookupFixturePromotion(Business $business, string $status, string $start, string $end): Promotion
{
    $promotion = $business->promotions()->make();
    $promotion->forceFill([
        'status' => $status,
        'local_start_date' => $status === 'draft' ? '2030-01-01' : null,
        'local_end_date' => $status === 'draft' ? '2030-01-03' : null,
        'target_points' => 8,
        'reward_title' => 'Un premio',
        'timezone_snapshot' => $status === 'draft' ? null : $business->timezone,
        'starts_at' => $status === 'draft' ? null : $start,
        'ends_at' => $status === 'draft' ? null : $end,
        'cancelled_at' => $status === 'cancelled' ? $start : null,
    ])->save();

    return $promotion;
}

/**
 * Reads complete domain rows to detect unintended lookup mutations.
 *
 * @return array<string, array<int, array<string, mixed>>> Existing facts in stable primary-key order.
 */
function lookupDomainSnapshot(): array
{
    $snapshot = [];
    foreach (['customer_passes', 'promotion_participations', 'visits', 'reward_entitlements'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $snapshot;
}
