<?php

use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Models\User;
use App\Support\BusinessSummary;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('distinguishes incomplete preparation from an active successfully empty Promotion', function () {
    summaryDatabaseTime();
    $business = Business::factory()->create();
    $reader = new BusinessSummary;

    $waiting = $reader->read($business->user);

    expect($waiting['statistics'])->toBe('waiting');
    expect($waiting['metrics'])->toBeNull();
    expect($waiting['appearancePrepared'])->toBeFalse();
    expect($waiting['promotionPrepared'])->toBeFalse();

    $business->update(['pass_background_color' => '#A77BFF']);
    $promotion = summaryPromotion($business);
    $active = $reader->read($business->user);

    expect($active['currentPromotion']->id)->toBe($promotion->id);
    expect($active['statistics'])->toBe('available');
    expect($active['metrics'])->toBe(['active_passes' => 0, 'awarded_points' => 0, 'unlocked_rewards' => 0, 'redeemed_rewards' => 0]);
    expect($active['appearancePrepared'])->toBeTrue();
    expect($active['promotionPrepared'])->toBeTrue();
});

it('derives half-open phases and keeps only relevant scheduled and terminal identities', function (string $status, string $start, string $end, string $slot) {
    summaryDatabaseTime();
    $business = Business::factory()->create();
    $promotion = summaryPromotion($business, [
        'status' => $status,
        'starts_at' => $start,
        'ends_at' => $end,
        'cancelled_at' => $status === 'cancelled' ? '2030-01-02 11:00:00+00' : null,
    ]);

    $summary = (new BusinessSummary)->read($business->user);

    expect($summary[$slot]->id)->toBe($promotion->id);
    expect($summary['promotionPrepared'])->toBeTrue();
    expect($summary['currentPromotion']?->id)->toBe($slot === 'currentPromotion' ? $promotion->id : null);
    expect($summary['statistics'])->toBe($slot === 'currentPromotion' ? 'available' : 'waiting');
    expect($summary['metrics'] === null)->toBe($slot !== 'currentPromotion');
})->with([
    'inclusive start' => ['published', '2030-01-02 12:00:00+00', '2030-01-03 00:00:00+00', 'currentPromotion'],
    'before start' => ['published', '2030-01-02 12:00:01+00', '2030-01-03 00:00:00+00', 'nextScheduled'],
    'exclusive end' => ['published', '2030-01-01 00:00:00+00', '2030-01-02 12:00:00+00', 'lastPromotion'],
    'cancelled active window' => ['cancelled', '2030-01-01 00:00:00+00', '2030-01-03 00:00:00+00', 'lastPromotion'],
    'cancelled before start' => ['cancelled', '2030-01-03 00:00:00+00', '2030-01-04 00:00:00+00', 'lastPromotion'],
]);

it('ignores drafts for preparation and picks the earliest scheduled and latest terminal Promotion', function () {
    summaryDatabaseTime();
    $business = Business::factory()->create();
    $foreign = Business::factory()->create();
    $foreign->promotions()->create(['local_start_date' => '2030-01-05', 'local_end_date' => '2030-01-06', 'target_points' => 8, 'reward_title' => 'Foreign draft']);
    expect((new BusinessSummary)->read($business->user)['hasPromotionDraft'])->toBeFalse();
    $business->promotions()->create([
        'local_start_date' => '2030-01-05',
        'local_end_date' => '2030-01-06',
        'target_points' => 8,
        'reward_title' => 'Draft',
    ]);
    $draft = (new BusinessSummary)->read($business->user);
    expect($draft['promotionPrepared'])->toBeFalse();
    expect($draft['hasPromotionDraft'])->toBeTrue();
    summaryPromotion($business, ['starts_at' => '2030-01-05 00:00:00+00', 'ends_at' => '2030-01-06 00:00:00+00']);
    $next = summaryPromotion($business, ['starts_at' => '2030-01-03 00:00:00+00', 'ends_at' => '2030-01-04 00:00:00+00']);
    summaryPromotion($business, ['starts_at' => '2029-12-01 00:00:00+00', 'ends_at' => '2029-12-02 00:00:00+00']);
    $last = summaryPromotion($business, ['starts_at' => '2030-01-01 00:00:00+00', 'ends_at' => '2030-01-02 00:00:00+00']);

    $summary = (new BusinessSummary)->read($business->user);

    expect($summary['nextScheduled']->id)->toBe($next->id);
    expect($summary['lastPromotion']->id)->toBe($last->id);
    expect($summary['promotionPrepared'])->toBeTrue();
    expect($summary['hasPromotionDraft'])->toBeTrue();
});

it('counts accepted activity and independent entitlements without fanout or historical recomputation', function () {
    summaryDatabaseTime();
    $business = Business::factory()->create();
    $promotion = summaryPromotion($business);
    $first = CustomerPass::factory()->for($business)->create();
    $second = CustomerPass::factory()->for($business)->create();
    summaryVisit($promotion, $first, 3);
    summaryVisit($promotion, $first, 5);
    summaryVisit($promotion, $second, 1);
    $inactivePass = CustomerPass::factory()->for($business)->create();
    DB::table('promotion_participations')->insert([
        'business_id' => $business->id,
        'customer_pass_id' => $inactivePass->id,
        'promotion_id' => $promotion->id,
    ]);
    summaryEntitlement($promotion, $first, redeemed: true);
    summaryEntitlement($promotion, $inactivePass);

    $old = summaryPromotion($business, ['starts_at' => '2029-12-01 00:00:00+00', 'ends_at' => '2029-12-02 00:00:00+00']);
    summaryVisit($old, $first, 100);
    summaryEntitlement($old, $first);

    $otherBusiness = Business::factory()->create();
    $foreign = summaryPromotion($otherBusiness);
    $foreignPass = CustomerPass::factory()->for($otherBusiness)->create();
    summaryVisit($foreign, $foreignPass, 200);
    summaryEntitlement($foreign, $foreignPass);

    $summary = (new BusinessSummary)->read($business->user);

    expect($summary['metrics'])->toBe(['active_passes' => 2, 'awarded_points' => 9, 'unlocked_rewards' => 2, 'redeemed_rewards' => 1]);
});

it('resolves ownership freshly rather than trusting cached Business relations', function () {
    summaryDatabaseTime();
    $owned = Business::factory()->create();
    $foreign = Business::factory()->create();
    summaryPromotion($foreign);
    $owner = $owned->user;
    $owner->setRelation('business', $foreign);

    $summary = (new BusinessSummary)->read($owner);

    expect($summary['business']->id)->toBe($owned->id);
    expect($summary['currentPromotion'])->toBeNull();
    expect($summary['promotionPrepared'])->toBeFalse();
    expect(fn () => (new BusinessSummary)->read(User::factory()->create()))->toThrow(ModelNotFoundException::class);
});

it('refuses an unverified owner instead of downgrading authorization failure to unavailable statistics', function () {
    $business = Business::factory()->for(User::factory()->unverified())->create();

    expect(fn () => (new BusinessSummary)->read($business->user))->toThrow(AuthorizationException::class);
});

it('reads frozen terms and metrics in one current PostgreSQL snapshot independently of application time', function () {
    $business = Business::factory()->create(['timezone' => 'America/La_Paz']);
    $promotion = summaryPromotion($business, ['starts_at' => '2000-01-01 00:00:00+00', 'ends_at' => '2100-01-01 00:00:00+00']);
    $promotion->extraPoints()->create(['weekday' => 1, 'multiplier' => 3]);
    $owner = $business->user;
    $this->travelTo(CarbonImmutable::parse('2200-01-01'));
    $before = CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS instant')->instant);
    DB::connection()->enableQueryLog();

    $summary = (new BusinessSummary)->read($owner);

    $queries = DB::getQueryLog();
    DB::connection()->disableQueryLog();
    $after = CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS instant')->instant);
    expect($summary['asOf'])->toBeInstanceOf(CarbonImmutable::class);
    expect($summary['asOf']->betweenIncluded($before, $after))->toBeTrue();
    expect($summary['currentPromotion']->starts_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($summary['currentPromotion']->timezone_snapshot)->toBe('Europe/Madrid');
    expect($summary['currentPromotion']->extraPoints->sole()->multiplier)->toBe(3);
    expect($summary['statistics'])->toBe('available');
    expect($queries)->toHaveCount(1);
});

it('re-evaluates scheduled active and ended phases on each fresh read', function () {
    summaryDatabaseTime('2030-01-01 23:59:59+00');
    $business = Business::factory()->create();
    $promotion = summaryPromotion($business, ['starts_at' => '2030-01-02 00:00:00+00']);
    $reader = new BusinessSummary;
    expect($reader->read($business->user)['nextScheduled']->id)->toBe($promotion->id);

    summaryDatabaseTime('2030-01-02 00:00:00+00');
    expect($reader->read($business->user)['currentPromotion']->id)->toBe($promotion->id);

    summaryDatabaseTime('2030-01-03 00:00:00+00');
    $ended = $reader->read($business->user);
    expect($ended['lastPromotion']->id)->toBe($promotion->id);
    expect($ended['currentPromotion'])->toBeNull();
    expect($ended['metrics'])->toBeNull();
    expect($ended['promotionPrepared'])->toBeTrue();
});

it('refuses a retry after the persisted ownership relationship changes', function () {
    summaryDatabaseTime();
    $business = Business::factory()->create();
    $owner = $business->user;
    $reader = new BusinessSummary;
    $reader->read($owner);
    $business->forceFill(['user_id' => User::factory()->create()->id])->save();

    expect(fn () => $reader->read($owner))->toThrow(ModelNotFoundException::class);
});

it('keeps fresh Promotion context on statistics outage and retries without previous identity or metrics', function () {
    summaryDatabaseTime();
    $business = Business::factory()->create();
    $first = summaryPromotion($business);
    $fail = true;
    DB::connection()->beforeExecuting(function (string $sql) use (&$fail): void {
        if ($fail && str_contains($sql, 'COUNT(DISTINCT')) {
            throw new QueryException('pgsql', $sql, [], new PDOException('Statistics read cancelled', 57014));
        }
    });
    $reader = new BusinessSummary;

    $unavailable = $reader->read($business->user);

    expect($unavailable['currentPromotion']->id)->toBe($first->id);
    expect($unavailable['statistics'])->toBe('unavailable');
    expect($unavailable['metrics'])->toBeNull();
    $fail = false;
    $first->forceFill(['status' => 'cancelled', 'cancelled_at' => '2030-01-02 12:00:00+00'])->save();
    $replacement = summaryPromotion($business);
    summaryVisit($replacement, CustomerPass::factory()->for($business)->create(), 5);

    $retried = $reader->read($business->user);

    expect($retried['currentPromotion']->id)->toBe($replacement->id);
    expect($retried['statistics'])->toBe('available');
    expect($retried['metrics']['awarded_points'])->toBe(5);
});

it('reselects context after a failed aggregate attempt instead of mixing previous facts', function () {
    summaryDatabaseTime();
    $business = Business::factory()->create();
    $first = summaryPromotion($business);
    $replacement = summaryPromotion($business, ['starts_at' => '2030-01-03 00:00:00+00', 'ends_at' => '2030-01-04 00:00:00+00']);
    $failed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$failed, $first): void {
        if (! $failed && str_contains($sql, 'COUNT(DISTINCT')) {
            $failed = true;
            DB::table('promotions')->where('id', $first->id)->update([
                'status' => 'cancelled', 'cancelled_at' => '2030-01-02 12:00:00+00',
            ]);
            summaryDatabaseTime('2030-01-03 00:00:00+00');

            throw new QueryException('pgsql', $sql, [], new PDOException('Statistics read cancelled', 57014));
        }
    });

    $summary = (new BusinessSummary)->read($business->user);

    expect($summary['currentPromotion']->id)->toBe($replacement->id);
    expect($summary['lastPromotion']->id)->toBe($first->id);
    expect($summary['asOf']->toIso8601String())->toBe('2030-01-03T00:00:00+00:00');
    expect($summary['statistics'])->toBe('unavailable');
    expect($summary['metrics'])->toBeNull();
});

it('propagates a context-only fallback outage without repeated reads or invented statistics', function () {
    summaryDatabaseTime();
    $business = Business::factory()->create();
    $attempts = 0;
    DB::connection()->beforeExecuting(function (string $sql) use (&$attempts): void {
        if (str_contains($sql, 'summary_clock')) {
            $attempts++;
            throw new QueryException('pgsql', $sql, [], new PDOException('Read cancelled', 57014));
        }
    });

    expect(fn () => (new BusinessSummary)->read($business->user))
        ->toThrow(fn (QueryException $exception) => expect((string) $exception->getCode())->toBe('57014'));
    expect($attempts)->toBe(2);
});

it('does not conceal programming errors as unavailable statistics', function () {
    summaryDatabaseTime();
    $business = Business::factory()->create();
    DB::connection()->beforeExecuting(function (string $sql): void {
        if (str_contains($sql, 'COUNT(DISTINCT')) {
            throw new LogicException('Broken aggregate contract');
        }
    });

    expect(fn () => (new BusinessSummary)->read($business->user))->toThrow(LogicException::class);
});

it('propagates unclassified query failures instead of inventing empty Business facts', function (int $sqlState) {
    summaryDatabaseTime();
    $business = Business::factory()->create();
    DB::connection()->beforeExecuting(function (string $sql) use ($sqlState): void {
        if (str_contains($sql, 'summary_clock')) {
            throw new QueryException('pgsql', $sql, [], new PDOException('Broken query contract', $sqlState));
        }
    });

    expect(fn () => (new BusinessSummary)->read($business->user))
        ->toThrow(fn (QueryException $exception) => expect((string) $exception->getCode())->toBe((string) $sqlState));
})->with(['schema' => 42703, 'permission' => 42501, 'unclassified' => 58000]);

/**
 * Freezes the PostgreSQL clock only inside this rolled-back testing transaction.
 *
 * @param  string  $instant  Deterministic PostgreSQL wall-clock value, independent of PHP test time.
 */
function summaryDatabaseTime(string $instant = '2030-01-02 12:00:00+00'): void
{
    expect(app()->environment('testing'))->toBeTrue();
    expect(DB::connection()->getDatabaseName())->toBe('testing');
    $literal = DB::connection()->getPdo()->quote($instant);
    DB::unprepared("CREATE OR REPLACE FUNCTION public.clock_timestamp() RETURNS timestamptz LANGUAGE SQL AS \$\$ SELECT {$literal}::timestamptz \$\$");
    DB::statement('SET LOCAL search_path TO public, pg_catalog');
}

/**
 * Persists frozen synthetic Promotion terms without publication or Wallet effects.
 *
 * @param  Business  $business  Fixture owner.
 * @param  array<string, mixed>  $overrides  Specific lifecycle/window facts for this scenario.
 * @return Promotion Persisted owned Promotion.
 */
function summaryPromotion(Business $business, array $overrides = []): Promotion
{
    $promotion = $business->promotions()->make();
    $promotion->forceFill([
        'status' => 'published',
        'target_points' => 8,
        'reward_title' => 'A coffee',
        'timezone_snapshot' => 'Europe/Madrid',
        'starts_at' => '2030-01-01 00:00:00+00',
        'ends_at' => '2030-01-03 00:00:00+00',
        ...$overrides,
    ])->save();

    return $promotion;
}

/**
 * Inserts one accepted fixture outcome without implementing operational acceptance.
 *
 * @param  Promotion  $promotion  Owned Promotion receiving the fact.
 * @param  CustomerPass  $pass  Same-Business anonymous pass.
 * @param  int  $points  Stored immutable awarded points.
 */
function summaryVisit(Promotion $promotion, CustomerPass $pass, int $points): void
{
    DB::table('visits')->insert([
        'business_id' => $pass->business_id,
        'promotion_id' => $promotion->id,
        'customer_pass_id' => $pass->id,
        'confirmed_by_user_id' => $pass->business->user_id,
        'operation_id' => (string) Str::uuid(),
        'awarded_points' => $points,
        'confirmed_at' => $promotion->starts_at->addHour(),
    ]);
}

/**
 * Inserts an independent entitlement fact without invoking unlock or redemption commands.
 *
 * @param  Promotion  $promotion  Owned Promotion receiving the entitlement.
 * @param  CustomerPass  $pass  Same-Business anonymous pass.
 * @param  bool  $redeemed  Whether this fixture contains a final redemption.
 */
function summaryEntitlement(Promotion $promotion, CustomerPass $pass, bool $redeemed = false): void
{
    DB::table('reward_entitlements')->insert([
        'business_id' => $pass->business_id,
        'promotion_id' => $promotion->id,
        'customer_pass_id' => $pass->id,
        'unlocked_at' => $promotion->starts_at->addHour(),
        'redeemed_at' => $redeemed ? $promotion->starts_at->addHours(2) : null,
        'redeemed_by_user_id' => $redeemed ? $pass->business->user_id : null,
    ]);
}
