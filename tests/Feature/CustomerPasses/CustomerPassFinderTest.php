<?php

use App\Data\Time\DatabaseTime;
use App\Enums\PassFindingFailure;
use App\Exceptions\CustomerPassFindingException;
use App\Integrations\GoogleWallet\GoogleWalletPassCreator;
use App\Integrations\GoogleWallet\GoogleWalletPassUpdater;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Models\User;
use App\Support\BusinessSummary;
use App\Support\CustomerPassFinder;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Guards cleanup before the framework can reset any PostgreSQL data. */
trait GuardsCustomerPassFinderDatabase
{
    use RefreshDatabase;

    /**
     * Refuses configured or actual databases outside the isolated testing runtime.
     *
     * @throws LogicException If the runtime or database is not the testing target.
     */
    protected function beforeRefreshingDatabase(): void
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

uses(GuardsCustomerPassFinderDatabase::class);

beforeEach(function () {
    config(['pass_lookup.max_invalid_attempts' => 3, 'pass_lookup.decay_seconds' => 60]);
});

it('identifies the same own-Business pass with exact token or canonical manual entry without writes', function (string $type, string $value, string $storedCode) {
    $pass = finderFixturePass($storedCode);
    $this->actingAs($pass->business->user);
    $this->mock(GoogleWalletPassCreator::class)->shouldNotReceive('create');
    $this->mock(GoogleWalletPassUpdater::class)->shouldNotReceive('update');
    $this->mock(BusinessSummary::class)->shouldNotReceive('read');
    $before = finderDomainSnapshot();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $first = app(CustomerPassFinder::class)->find(auth()->user(), $type, $value);
    $second = app(CustomerPassFinder::class)->find(auth()->user(), $type, $value);

    expect($first['status'])->toBe('found');
    expect($first['customer_pass_id'])->toBe($pass->id);
    expect($first['promotion'])->toBeNull();
    expect($first['can_accept_visit'])->toBeFalse();
    expect($first['can_redeem_reward'])->toBeFalse();
    expect(array_keys($first))->toBe([
        'status', 'customer_pass_id', 'checked_at', 'promotion', 'can_accept_visit', 'can_redeem_reward',
    ]);
    expect(array_diff_key($first, ['checked_at' => true]))->toBe(array_diff_key($second, ['checked_at' => true]));
    expect(finderDomainSnapshot())->toBe($before);
    expect($writes)->toBeEmpty();
    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(0);
})->with([
    'opaque scan token' => ['token', str_repeat('a', 64), '00007'],
    'leading zeros with outer keyboard whitespace' => ['manual', " \t00007\r\n", '00007'],
    'minimum manual code' => ['manual', '00000', '00000'],
    'maximum manual code' => ['manual', '99999', '99999'],
    'canonical legacy code' => ['manual', ' AB12CD34EF56 ', 'AB12CD34EF56'],
]);

it('rejects unauthorized actors without consuming credential attempts or exposing state', function (string $actor) {
    $pass = finderFixturePass();
    if ($actor === 'unverified') {
        $pass->business->user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($pass->business->user);
    } elseif ($actor === 'without Business') {
        $this->actingAs(User::factory()->create());
    }
    $before = finderDomainSnapshot();
    Log::spy();

    $failure = passFindingFailure(fn () => app(CustomerPassFinder::class)->find(auth()->user(), 'token', str_repeat('a', 64)));

    Log::shouldNotHaveReceived('log');
    expect($failure->failure)->toBe(PassFindingFailure::Forbidden);
    expect($failure->retryAfterSeconds)->toBeNull();
    expect($failure->getPrevious())->toBeNull();
    expect(finderDomainSnapshot())->toBe($before);
    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(0);
})->with(['anonymous', 'unverified', 'without Business']);

it('uses only the explicit trusted actor and never substitutes the ambient session', function () {
    $own = finderFixturePass();
    $foreign = finderFixturePass('00007', str_repeat('b', 64));
    $this->actingAs($foreign->business->user);
    $before = finderDomainSnapshot();

    $found = app(CustomerPassFinder::class)->find($own->business->user, 'manual', '00007');
    $failure = passFindingFailure(fn () => app(CustomerPassFinder::class)->find(null, 'manual', '00007'));

    expect($found['customer_pass_id'])->toBe($own->id)->not->toBe($foreign->id);
    expect($failure->failure)->toBe(PassFindingFailure::Forbidden);
    expect(RateLimiter::attempts('pass-lookup:'.$own->business->user_id))->toBe(0);
    expect(RateLimiter::attempts('pass-lookup:'.$foreign->business->user_id))->toBe(0);
    expect(finderDomainSnapshot())->toBe($before);
});

it('never reveals a foreign token while duplicate manual codes remain Business scoped', function () {
    $own = finderFixturePass();
    $foreign = finderFixturePass('00007', str_repeat('b', 64));
    $this->actingAs($own->business->user);
    $before = finderDomainSnapshot();

    $rejected = passFindingFailure(fn () => app(CustomerPassFinder::class)->find(auth()->user(), 'token', str_repeat('b', 64)));
    $found = app(CustomerPassFinder::class)->find(auth()->user(), 'manual', '00007');

    expect($rejected->failure)->toBe(PassFindingFailure::InvalidCredential);
    expect($rejected->retryAfterSeconds)->toBeNull();
    expect($found['customer_pass_id'])->toBe($own->id)->not->toBe($foreign->id);
    expect(finderDomainSnapshot())->toBe($before);
    expect(RateLimiter::attempts('pass-lookup:'.$own->business->user_id))->toBe(1);
});

it('rejects invalid or public identifiers uniformly without leaking them', function (string $type, string $value) {
    $pass = finderFixturePass();
    $this->actingAs($pass->business->user);
    $before = finderDomainSnapshot();
    Log::spy();

    $failure = passFindingFailure(fn () => app(CustomerPassFinder::class)->find(auth()->user(), $type, $value));

    expect($failure->failure)->toBe(PassFindingFailure::InvalidCredential);
    expect($failure->retryAfterSeconds)->toBeNull();
    expect($failure->getPrevious())->toBeNull();
    expect($failure->getMessage())->toBe('invalid_credential');
    app(ExceptionHandler::class)->report($failure);

    Log::shouldNotHaveReceived('error');
    expect(finderDomainSnapshot())->toBe($before);
    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(1);
    Log::shouldHaveReceived('log')->once()->with('notice', 'customer_pass.lookup_rejected', [
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
    'internal manual spaces' => ['manual', '00 007'],
    'manual hyphens' => ['manual', '00-007'],
    'lowercase legacy code' => ['manual', 'ab12cd34ef56'],
    'separated legacy code' => ['manual', 'AB12-CD34 EF56'],
    'automatic token with outer space' => ['token', ' '.str_repeat('a', 64)],
    'automatic token with uppercase letters' => ['token', str_repeat('A', 64)],
    'missing private token' => ['token', str_repeat('f', 64)],
    'oversized input' => ['manual', str_repeat('0', 257)],
]);

it('rejects malformed credentials before reading the database clock or pass state', function (string $method, string $credential) {
    $pass = finderFixturePass();
    $before = finderDomainSnapshot();
    $this->mock(DatabaseClock::class)
        ->shouldReceive('capture')
        ->never()
        ->andThrow(new LogicException('Malformed input reached the database clock.'));
    Log::spy();

    $failure = passFindingFailure(fn () => app(CustomerPassFinder::class)->find(
        $pass->business->user,
        $method,
        $credential,
    ));

    expect($failure->failure)->toBe(PassFindingFailure::InvalidCredential);
    expect($failure->getPrevious())->toBeNull();
    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(1);
    expect(finderDomainSnapshot())->toBe($before);
    Log::shouldHaveReceived('log')->once()->with('notice', 'customer_pass.lookup_rejected', [
        'actor_id' => $pass->business->user_id,
        'business_id' => $pass->business_id,
        'outcome' => 'rejected',
        'reason' => 'invalid_credential',
    ]);
})->with([
    'unknown method' => ['public_id', '00007'],
    'manual separator' => ['manual', '00-007'],
    'malformed automatic token' => ['token', 'not-a-private-token'],
]);

it('temporarily restricts rotating invalid credentials across both input methods without adding blocked attempts', function () {
    $pass = finderFixturePass();
    $this->actingAs($pass->business->user);
    $before = finderDomainSnapshot();
    Log::spy();

    foreach (['00001', '00002', '00003'] as $code) {
        expect(passFindingFailure(fn () => app(CustomerPassFinder::class)->find(auth()->user(), 'manual', $code))->failure)
            ->toBe(PassFindingFailure::InvalidCredential);
    }
    $blocked = passFindingFailure(fn () => app(CustomerPassFinder::class)->find(auth()->user(), 'token', str_repeat('a', 64)));

    expect($blocked->failure)->toBe(PassFindingFailure::InvalidAttemptLimit);
    expect($blocked->retryAfterSeconds)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    expect($blocked->getPrevious())->toBeNull();
    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(3);
    expect(finderDomainSnapshot())->toBe($before);
    Log::shouldHaveReceived('log')->with('notice', 'customer_pass.lookup_throttled', [
        'actor_id' => $pass->business->user_id,
        'business_id' => $pass->business_id,
        'outcome' => 'rejected',
        'reason' => 'invalid_attempt_limit',
    ])->once();

    $this->travel(61)->seconds();
    expect(app(CustomerPassFinder::class)->find(auth()->user(), 'manual', '00007')['status'])->toBe('found');
    $this->travelBack();
});

it('derives eligibility from one database-time hint and rejects inactive boundaries', function (string $phase, string $start, string $end, bool $active) {
    $pass = finderFixturePass();
    $this->actingAs($pass->business->user);
    $promotion = finderFixturePromotion($pass->business, $phase, $start, $end);
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()->with($pass->business->timezone)
        ->andReturn(new DatabaseTime('2030-01-02 12:00:00+00', '2030-01-02'));
    $before = finderDomainSnapshot();

    $result = app(CustomerPassFinder::class)->find(auth()->user(), 'manual', '00007');

    expect($result['status'])->toBe('found');
    expect($result['checked_at'])->toBe('2030-01-02 12:00:00+00');
    expect($result['can_accept_visit'])->toBe($active);
    expect($result['can_redeem_reward'])->toBeFalse();
    expect($result['promotion']['id'] ?? null)->toBe($active ? $promotion->id : null);
    expect(finderDomainSnapshot())->toBe($before);
})->with([
    'inclusive start' => ['published', '2030-01-02 12:00:00+00', '2030-01-03 12:00:00+00', true],
    'exclusive end' => ['published', '2030-01-01 12:00:00+00', '2030-01-02 12:00:00+00', false],
    'scheduled' => ['published', '2030-01-03 12:00:00+00', '2030-01-04 12:00:00+00', false],
    'ended' => ['published', '2030-01-01 12:00:00+00', '2030-01-02 11:59:59+00', false],
    'cancelled' => ['cancelled', '2030-01-01 12:00:00+00', '2030-01-03 12:00:00+00', false],
    'draft' => ['draft', '2030-01-01 12:00:00+00', '2030-01-03 12:00:00+00', false],
]);

it('rejects multiple active Promotion matches without changing facts or invalid-attempt history', function (string $method, string $credential) {
    $pass = finderFixturePass();
    $actor = $pass->business->user;
    finderFixturePromotion($pass->business, 'published', '2030-01-01 12:00:00+00', '2030-01-03 12:00:00+00');
    finderFixturePromotion($pass->business, 'published', '2030-01-01 12:00:00+00', '2030-01-03 12:00:00+00');
    expect(passFindingFailure(fn () => app(CustomerPassFinder::class)->find($actor, 'manual', 'invalid'))->failure)
        ->toBe(PassFindingFailure::InvalidCredential);

    $this->mock(DatabaseClock::class)
        ->shouldReceive('capture')
        ->once()
        ->with($pass->business->timezone)
        ->andReturn(new DatabaseTime('2030-01-02 12:00:00+00', '2030-01-02'));
    $finder = app(CustomerPassFinder::class);
    $this->mock(GoogleWalletPassCreator::class)->shouldNotReceive('create');
    $this->mock(GoogleWalletPassUpdater::class)->shouldNotReceive('update');
    $before = finderDomainSnapshot();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\\s*(insert|update|delete)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    Log::spy();

    $failure = passFindingFailure(fn () => $finder->find($actor, $method, $credential));

    expect($failure->failure)->toBe(PassFindingFailure::AmbiguousActivePromotion);
    expect($failure->retryAfterSeconds)->toBeNull();
    expect($failure->getMessage())->toBe('ambiguous_active_promotion');
    expect($failure->getPrevious())->toBeNull();
    expect(RateLimiter::attempts('pass-lookup:'.$actor->id))->toBe(1);
    expect(finderDomainSnapshot())->toBe($before);
    expect($writes)->toBeEmpty();

    app(ExceptionHandler::class)->report($failure);

    Log::shouldNotHaveReceived('error');
    Log::shouldHaveReceived('log')->once()->with('error', 'customer_pass.lookup_unavailable', [
        'actor_id' => $actor->id,
        'business_id' => $pass->business_id,
        'outcome' => 'unavailable',
        'reason' => 'ambiguous_active_promotion',
    ]);
})->with([
    'manual credential' => ['manual', '00007'],
    'private scan token' => ['token', str_repeat('a', 64)],
]);

it('uses actual PostgreSQL time despite an incorrect application clock and unavailable Summary reader', function () {
    $pass = finderFixturePass();
    $this->actingAs($pass->business->user);
    $instant = DB::selectOne('SELECT clock_timestamp() AS instant')->instant;
    $time = CarbonImmutable::parse($instant);
    finderFixturePromotion($pass->business, 'published', $time->subHour()->toIso8601String(), $time->addHour()->toIso8601String());
    $this->travelTo('2040-01-01');
    $this->mock(BusinessSummary::class)->shouldNotReceive('read');
    $before = finderDomainSnapshot();

    $result = app(CustomerPassFinder::class)->find(auth()->user(), 'manual', '00007');

    expect($result['can_accept_visit'])->toBeTrue();
    expect(CarbonImmutable::parse($result['checked_at'])->year)->toBe($time->year);
    expect(finderDomainSnapshot())->toBe($before);
    $this->travelBack();
});

it('reports immutable awarded points and existing Reward facts without granting or redeeming anything', function (int $points, string $reward, bool $visit, bool $redeem) {
    $pass = finderFixturePass();
    $this->actingAs($pass->business->user);
    $promotion = finderFixturePromotion($pass->business, 'published', '2030-01-01 12:00:00+00', '2030-01-03 12:00:00+00');
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
    $before = finderDomainSnapshot();

    $result = app(CustomerPassFinder::class)->find(auth()->user(), 'manual', '00007');

    expect($result['promotion'])->toBe([
        'id' => $promotion->id, 'target_points' => 8, 'earned_points' => $points,
        'reward_title' => 'Un premio', 'reward_description' => $promotion->reward_description, 'reward_status' => $reward, 'visit_points' => 1,
    ]);
    expect($result['can_accept_visit'])->toBe($visit);
    expect($result['can_redeem_reward'])->toBe($redeem);
    expect(finderDomainSnapshot())->toBe($before);
})->with([
    'below goal' => [3, 'none', true, false],
    'completion does not grant missing entitlement' => [8, 'none', false, false],
    'available reward' => [8, 'available', false, true],
    'redeemed reward' => [8, 'redeemed', false, false],
]);

it('previews frozen Promotion points at the single captured instant without writes or provider work', function (string $instant, ?string $start, ?string $end, int $multiplier, int $expected) {
    $pass = finderFixturePass();
    $promotion = finderFixturePromotion($pass->business, 'published', '2030-01-01 00:00:00+00', '2030-01-04 00:00:00+00');
    $promotion->forceFill(['timezone_snapshot' => 'America/Bogota'])->save();
    $pass->business->update(['timezone' => 'Pacific/Auckland']);
    $promotion->extraPoints()->create(['weekday' => 3, 'start_time' => $start, 'end_time' => $end, 'multiplier' => $multiplier]);
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()->with('Pacific/Auckland')
        ->andReturn(new DatabaseTime($instant, '2030-01-03'));
    $this->mock(GoogleWalletPassCreator::class)->shouldNotReceive('create');
    $this->mock(GoogleWalletPassUpdater::class)->shouldNotReceive('update');
    $before = finderDomainSnapshot();
    $actor = $pass->business->user;
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $this->travelTo('2040-01-01');

    $result = app(CustomerPassFinder::class)->find($actor, 'manual', '00007');

    expect($result['promotion']['visit_points'])->toBe($expected);
    expect($result['checked_at'])->toBe($instant);
    expect($queries)->toHaveCount(2);
    expect(implode(' ', $queries))->not->toMatch('/clock_timestamp|current_timestamp|now\(|^\s*(insert|update|delete)/i');
    expect(finderDomainSnapshot())->toBe($before);
    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(0);
    $this->travelBack();
})->with([
    'base outside window' => ['2030-01-02 11:59:59.999999+00', '07:00:00', '08:00:00', 3, 1],
    'x2 whole day' => ['2030-01-02 12:00:00+00', null, null, 2, 2],
    'x3 inclusive start' => ['2030-01-02 12:00:00+00', '07:00:00', '08:00:00', 3, 3],
    'x5 last microsecond' => ['2030-01-02 12:59:59.999999+00', '07:00:00', '08:00:00', 5, 5],
    'exclusive end' => ['2030-01-02 13:00:00+00', '07:00:00', '08:00:00', 3, 1],
    'frozen local weekday' => ['2030-01-03 02:00:00+00', null, null, 5, 5],
]);

it('fails closed with sanitized diagnostics when the authoritative clock read fails', function (string $source) {
    $pass = finderFixturePass();
    $this->actingAs($pass->business->user);
    $before = finderDomainSnapshot();
    $native = $source === 'query'
        ? new QueryException('pgsql', 'select secret', ['00007'], new PDOException('private provider error'))
        : new PDOException('private provider error');
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()->andThrow($native);
    Log::spy();
    $payload = null;
    Log::shouldReceive('log')
        ->once()
        ->with('error', 'customer_pass.lookup_unavailable', Mockery::capture($payload));

    $failure = passFindingFailure(fn () => app(CustomerPassFinder::class)->find(auth()->user(), 'manual', '00007'));

    expect($failure->failure)->toBe(PassFindingFailure::AuthoritativeReadFailed);
    expect($failure->retryAfterSeconds)->toBeNull();
    expect($failure->getPrevious())->toBeNull();
    expect($failure->getMessage())->toBe('authoritative_read_failed');
    expect(finderDomainSnapshot())->toBe($before);
    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(0);
    expect($payload['reason'])->toBe('authoritative_read_failed');
    expect($payload['actor_id'])->toBe($pass->business->user_id);
    expect($payload['business_id'])->toBe($pass->business_id);
    expect($payload['outcome'])->toBe('unavailable');
    expect($payload['previous_class'])->toBe($native::class);
    expect($payload['exception_class'])->toBe($native::class);
    expect($payload['source_file'])->toBe($native->getFile());
    expect($payload['source_line'])->toBe($native->getLine());
    expect($payload['exception_frames'])->toHaveCount(count($native->getTrace()));
    foreach ($payload['exception_frames'] as $index => $frame) {
        expect(array_diff(array_keys($frame), ['file', 'line', 'class', 'function']))->toBeEmpty();
        foreach ($frame as $key => $value) {
            expect($value)->toBe($native->getTrace()[$index][$key]);
        }
    }
    expect(json_encode($payload, JSON_THROW_ON_ERROR))
        ->not->toContain('00007', 'private provider error', 'select secret');
    app(ExceptionHandler::class)->report($failure);
    Log::shouldNotHaveReceived('error');
})->with(['query', 'driver']);

it('propagates logging failures without repeating the rejected search or its limiter effect', function () {
    $pass = finderFixturePass();
    $before = finderDomainSnapshot();
    $loggingFailure = new RuntimeException('synthetic logging failure');
    Log::shouldReceive('log')->once()->andThrow($loggingFailure);

    expect(fn () => app(CustomerPassFinder::class)->find($pass->business->user, 'manual', 'invalid'))
        ->toThrow($loggingFailure);

    expect(RateLimiter::attempts('pass-lookup:'.$pass->business->user_id))->toBe(1);
    expect(finderDomainSnapshot())->toBe($before);
});

/**
 * Creates an issued credential fixture with its own verified Business owner.
 *
 * @param  string  $code  Business-scoped manual identifier.
 * @param  string  $token  Synthetic private scan value.
 * @return CustomerPass Persisted identity without provider calls.
 */
function finderFixturePass(string $code = '00007', string $token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): CustomerPass
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
function finderFixturePromotion(Business $business, string $status, string $start, string $end): Promotion
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
 * Reads complete domain rows to detect unintended finder mutations.
 *
 * @return array<string, array<int, array<string, mixed>>> Existing facts in stable primary-key order.
 */
function finderDomainSnapshot(): array
{
    $snapshot = [];
    foreach (['customer_passes', 'promotion_participations', 'visits', 'reward_entitlements'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $snapshot;
}

/**
 * Captures the safe rejection emitted by the public finder boundary.
 *
 * @param  callable(): array  $find  Search expected to reject without returning data.
 * @return CustomerPassFindingException Classified failure for assertions on safe fields.
 *
 * @throws RuntimeException If the operation incorrectly returns instead of rejecting.
 */
function passFindingFailure(callable $find): CustomerPassFindingException
{
    try {
        $find();
    } catch (CustomerPassFindingException $failure) {
        return $failure;
    }

    throw new RuntimeException('Expected a pass-finding exception, but data was returned.');
}
