<?php

use App\Actions\Promotions\PublishPromotion;
use App\Actions\Promotions\SavePromotionDraft;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('publishes current local dates as an immutable UTC window after locking the business', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $today = (string) DB::scalar('SELECT (clock_timestamp() AT TIME ZONE ?)::date', [$business->timezone]);
    $draft = publicationDraftForTest($owner, $today, $today, [
        ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'multiplier' => 3],
    ]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $published = app(PublishPromotion::class)->handle($owner, $draft, $business->timezone);

    $lockIndexes = array_keys(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'for update')));
    $clockIndexes = array_keys(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'clock_timestamp()')));
    $expectedStart = CarbonImmutable::parse($today.' 00:00:00', $business->timezone)->utc();
    $expectedEnd = CarbonImmutable::parse($today.' 00:00:00', $business->timezone)->addDay()->utc();

    expect($published->id)->toBe($draft->id)
        ->and($published->status)->toBe(PromotionStatus::Published)
        ->and($published->local_start_date)->toBeNull()
        ->and($published->local_end_date)->toBeNull()
        ->and($published->timezone_snapshot)->toBe('America/La_Paz')
        ->and($published->starts_at->toIso8601String())->toBe($expectedStart->toIso8601String())
        ->and($published->ends_at->toIso8601String())->toBe($expectedEnd->toIso8601String())
        ->and($published->target_points)->toBe(8)
        ->and($published->reward_title)->toBe('A coffee with pastry')
        ->and($published->extraPoints)->toHaveCount(1)
        ->and($published->extraPoints->first()->multiplier)->toBe(3)
        ->and($clockIndexes)->toHaveCount(1)
        ->and($lockIndexes)->toHaveCount(2)
        ->and(max($lockIndexes))->toBeLessThan($clockIndexes[0]);
});

it('converts a daylight-saving date range using local exclusive midnight', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    $draft = publicationDraftForTest($owner, '2030-03-30', '2030-03-31');

    $published = app(PublishPromotion::class)->handle($owner, $draft, 'Europe/Madrid');

    expect($published->starts_at->toIso8601String())->toBe('2030-03-29T23:00:00+00:00')
        ->and($published->ends_at->toIso8601String())->toBe('2030-03-31T22:00:00+00:00')
        ->and($published->starts_at->diffInHours($published->ends_at))->toBe(47.0);
});

it('requires renewed confirmation when the business timezone changed after review', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    $draft = publicationDraftForTest($owner, '2035-04-01', '2035-04-03');
    $business->update(['timezone' => 'UTC']);

    expect(fn () => app(PublishPromotion::class)->handle($owner, $draft, 'Europe/Madrid'))
        ->toThrow(ValidationException::class);

    expect($draft->fresh()->status)->toBe(PromotionStatus::Draft)
        ->and($draft->fresh()->timezone_snapshot)->toBeNull()
        ->and($draft->fresh()->local_start_date->toDateString())->toBe('2035-04-01');
});

it('rejects a draft whose start date passed before publication', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $today = CarbonImmutable::parse((string) DB::scalar('SELECT (clock_timestamp() AT TIME ZONE ?)::date', ['UTC']));
    $startDate = $today->subDay()->toDateString();
    $draft = publicationDraftRowForTest($business, $startDate, $today->toDateString());

    expect(fn () => app(PublishPromotion::class)->handle($owner, $draft, 'UTC'))
        ->toThrow(ValidationException::class);

    expect($draft->fresh()->status)->toBe(PromotionStatus::Draft)
        ->and($draft->fresh()->local_start_date->toDateString())->toBe($startDate)
        ->and($draft->fresh()->starts_at)->toBeNull();
});

it('does not publish the same draft a second time', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $draft = publicationDraftForTest($owner, '2035-04-01', '2035-04-03');
    $action = app(PublishPromotion::class);
    $published = $action->handle($owner, $draft, 'UTC');
    $previousStart = $published->starts_at;

    expect(fn () => $action->handle($owner, $draft, 'UTC'))->toThrow(ValidationException::class);

    expect($draft->fresh()->status)->toBe(PromotionStatus::Published)
        ->and($draft->fresh()->starts_at->equalTo($previousStart))->toBeTrue();
    $this->assertDatabaseCount('promotions', 1);
});

it('forbids another business owner from publishing a draft', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $otherOwner = User::factory()->create();
    Business::factory()->for($otherOwner)->create(['timezone' => 'UTC']);
    $draft = publicationDraftForTest($owner, '2035-04-01', '2035-04-03');

    expect(fn () => app(PublishPromotion::class)->handle($otherOwner, $draft, 'UTC'))
        ->toThrow(ModelNotFoundException::class);

    expect($draft->fresh()->status)->toBe(PromotionStatus::Draft)
        ->and($draft->fresh()->starts_at)->toBeNull();
});

it('rejects candidates that intersect effective published occupancy', function (array $existing, string $startDate, string $endDate) {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    publicationOccupancyForTest($business, ...$existing);
    $draft = publicationDraftForTest($owner, $startDate, $endDate);

    expect(fn () => app(PublishPromotion::class)->handle($owner, $draft, 'UTC'))
        ->toThrow(ValidationException::class);

    expect($draft->fresh()->status)->toBe(PromotionStatus::Draft)
        ->and($draft->fresh()->local_start_date->toDateString())->toBe($startDate)
        ->and($draft->fresh()->starts_at)->toBeNull();
})->with([
    'published interval intersection' => [['2035-04-01 00:00:00+00', '2035-04-08 00:00:00+00', null], '2035-04-07', '2035-04-10'],
    'intersection before cancellation truncation' => [['2035-04-01 00:00:00+00', '2035-04-08 00:00:00+00', '2035-04-05 12:00:00+00'], '2035-04-05', '2035-04-06'],
]);

it('publishes at touching boundaries and outside cancelled occupancy', function (array $existing, string $startDate, string $endDate) {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    publicationOccupancyForTest($business, ...$existing);
    $draft = publicationDraftForTest($owner, $startDate, $endDate);

    $published = app(PublishPromotion::class)->handle($owner, $draft, 'UTC');

    expect($published->status)->toBe(PromotionStatus::Published)
        ->and($published->local_start_date)->toBeNull()
        ->and($published->starts_at)->not->toBeNull();
})->with([
    'touching exclusive endpoint' => [['2035-04-01 00:00:00+00', '2035-04-08 00:00:00+00', null], '2035-04-08', '2035-04-10'],
    'cancelled before the scheduled start' => [['2035-04-08 00:00:00+00', '2035-04-10 00:00:00+00', '2035-04-07 12:00:00+00'], '2035-04-08', '2035-04-09'],
    'starts after intraday cancellation' => [['2035-04-01 00:00:00+00', '2035-04-10 00:00:00+00', '2035-04-05 12:00:00+00'], '2035-04-06', '2035-04-07'],
]);

/**
 * Saves one valid draft with its supplied local dates and optional multiplier windows.
 *
 * @param  list<array{weekday: int, start_time: string, end_time: string, multiplier: int}>  $windows  Multiplier windows stored with the draft.
 * @return Promotion Persisted draft selected for publication.
 */
function publicationDraftForTest(User $owner, string $startDate, string $endDate, array $windows = []): Promotion
{
    return app(SavePromotionDraft::class)->handle($owner, [
        'local_start_date' => $startDate,
        'local_end_date' => $endDate,
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
        'reward_description' => 'Any small coffee and pastry.',
        'extra_points' => $windows,
    ]);
}

/**
 * Creates a complete draft row directly for cases that application validation must reject at publication.
 *
 * @return Promotion Persisted draft with otherwise valid required fields.
 */
function publicationDraftRowForTest(Business $business, string $startDate, string $endDate): Promotion
{
    $id = DB::table('promotions')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'business_id' => $business->id,
        'local_start_date' => $startDate,
        'local_end_date' => $endDate,
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
        'status' => PromotionStatus::Draft->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Promotion::query()->findOrFail($id);
}

/**
 * Persists a published or cancelled UTC interval used to check effective occupancy.
 */
function publicationOccupancyForTest(Business $business, string $startsAt, string $endsAt, ?string $cancelledAt): void
{
    DB::table('promotions')->insert([
        'public_id' => (string) Str::uuid(),
        'business_id' => $business->id,
        'local_start_date' => null,
        'local_end_date' => null,
        'target_points' => 8,
        'reward_title' => 'Existing promotion',
        'status' => $cancelledAt === null ? PromotionStatus::Published->value : PromotionStatus::Cancelled->value,
        'timezone_snapshot' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'cancelled_at' => $cancelledAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
