<?php

use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use App\Support\BusinessSummary;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reports preparation facts without inventing an unfinished statistics contract', function () {
    summaryDatabaseTime();
    $business = Business::factory()->create();
    $reader = new BusinessSummary;

    $waiting = $reader->read($business->user);

    expect(array_key_exists('statistics', $waiting))->toBeFalse();
    expect(array_key_exists('metrics', $waiting))->toBeFalse();
    expect($waiting['currentPromotion'])->toBeNull();
    expect($waiting['appearancePrepared'])->toBeFalse();
    expect($waiting['promotionPrepared'])->toBeFalse();

    $business->update(['pass_background_color' => '#A77BFF']);
    $promotion = summaryPromotion($business);
    $active = $reader->read($business->user);

    expect($active['currentPromotion']->id)->toBe($promotion->id);

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
    $business->promotions()->create([
        'local_start_date' => '2030-01-05',
        'local_end_date' => '2030-01-06',
        'target_points' => 8,
        'reward_title' => 'Draft',
    ]);
    expect((new BusinessSummary)->read($business->user)['promotionPrepared'])->toBeFalse();
    summaryPromotion($business, ['starts_at' => '2030-01-05 00:00:00+00', 'ends_at' => '2030-01-06 00:00:00+00']);
    $next = summaryPromotion($business, ['starts_at' => '2030-01-03 00:00:00+00', 'ends_at' => '2030-01-04 00:00:00+00']);
    summaryPromotion($business, ['starts_at' => '2029-12-01 00:00:00+00', 'ends_at' => '2029-12-02 00:00:00+00']);
    $last = summaryPromotion($business, ['starts_at' => '2030-01-01 00:00:00+00', 'ends_at' => '2030-01-02 00:00:00+00']);

    $summary = (new BusinessSummary)->read($business->user);

    expect($summary['nextScheduled']->id)->toBe($next->id);
    expect($summary['lastPromotion']->id)->toBe($last->id);
    expect($summary['promotionPrepared'])->toBeTrue();
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

it('refuses an unverified owner', function () {
    $business = Business::factory()->for(User::factory()->unverified())->create();

    expect(fn () => (new BusinessSummary)->read($business->user))->toThrow(AuthorizationException::class);
});

it('reads frozen terms in one current PostgreSQL snapshot independently of application time', function () {
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

it('propagates context query failures instead of inventing an empty Business', function () {
    summaryDatabaseTime();
    $business = Business::factory()->create();
    DB::connection()->beforeExecuting(function (string $sql): void {
        if (str_contains($sql, 'summary_clock')) {
            throw new QueryException('pgsql', $sql, [], new PDOException('Undefined column', 42703));
        }
    });

    expect(fn () => (new BusinessSummary)->read($business->user))->toThrow(QueryException::class);
});

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
