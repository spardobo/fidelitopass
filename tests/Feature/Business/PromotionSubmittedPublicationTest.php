<?php

use App\Actions\Promotions\PublishPromotion;
use App\Actions\Promotions\SavePromotionDraft;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\User;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('publishes submitted terms and rules without creating an intermediate draft', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $startDate = submittedPublicationFutureDate($business->timezone);
    $input = submittedPublicationInput($startDate, 'Submitted reward', [
        ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '11:00', 'multiplier' => 3],
    ]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $published = app(PublishPromotion::class)->handleSubmitted($owner, $input, $business->timezone);

    $clockIndexes = array_keys(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'clock_timestamp()')));
    $lockIndexes = array_keys(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'for update')));
    $expectedStart = CarbonImmutable::parse($startDate.' 00:00:00', $business->timezone)->utc();

    expect($published->status)->toBe(PromotionStatus::Published)
        ->and($published->local_start_date)->toBeNull()
        ->and($published->local_end_date)->toBeNull()
        ->and($published->timezone_snapshot)->toBe($business->timezone)
        ->and($published->starts_at->toIso8601String())->toBe($expectedStart->toIso8601String())
        ->and($published->ends_at->toIso8601String())->toBe(
            CarbonImmutable::parse($startDate.' 00:00:00', $business->timezone)->addDays(7)->utc()->toIso8601String(),
        )
        ->and($published->target_points)->toBe(12)
        ->and($published->reward_title)->toBe('Submitted reward')
        ->and($published->reward_description)->toBe('A submitted description.')
        ->and($published->extraPoints)->toHaveCount(1)
        ->and($published->extraPoints->first()->multiplier)->toBe(3)
        ->and($published->created_at->equalTo($published->updated_at))->toBeTrue()
        ->and($clockIndexes)->toHaveCount(1)
        ->and($lockIndexes)->not->toBeEmpty()
        ->and(max($lockIndexes))->toBeLessThan($clockIndexes[0]);

    $this->assertDatabaseCount('promotions', 1);
});

it('replaces a reviewed draft aggregate with the submitted terms and complete rule set', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $startDate = submittedPublicationFutureDate('UTC');
    $oldStart = CarbonImmutable::parse($startDate)->addDay()->toDateString();
    $draft = app(SavePromotionDraft::class)->handle($owner, submittedPublicationInput($oldStart, 'Saved draft', [
        ['weekday' => 2, 'start_time' => '08:00', 'end_time' => '10:00', 'multiplier' => 2],
    ]));

    $published = app(PublishPromotion::class)->handleSubmitted($owner, submittedPublicationInput($startDate, 'Reviewed unsaved reward', [
        ['weekday' => 4, 'start_time' => null, 'end_time' => null, 'multiplier' => 5],
    ]), 'UTC', $draft);

    expect($published->id)->toBe($draft->id)
        ->and($published->status)->toBe(PromotionStatus::Published)
        ->and($published->reward_title)->toBe('Reviewed unsaved reward')
        ->and($published->local_start_date)->toBeNull()
        ->and($published->extraPoints)->toHaveCount(1)
        ->and($published->extraPoints->first()->weekday)->toBe(4)
        ->and($published->extraPoints->first()->multiplier)->toBe(5);

    $this->assertDatabaseCount('promotions', 1);
});

it('leaves an existing draft and its rules unchanged when submitted rules are invalid', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $startDate = submittedPublicationFutureDate('UTC');
    $draft = app(SavePromotionDraft::class)->handle($owner, submittedPublicationInput($startDate, 'Saved draft', [
        ['weekday' => 2, 'start_time' => '08:00', 'end_time' => '10:00', 'multiplier' => 2],
    ]));
    $before = $draft->fresh()->toArray();
    $beforeRules = $draft->extraPoints()->orderBy('id')->get()->toArray();
    $invalid = submittedPublicationInput($startDate, 'Must not persist', [
        ['weekday' => 2, 'start_time' => '08:00', 'end_time' => '10:00', 'multiplier' => 2],
        ['weekday' => 2, 'start_time' => '09:00', 'end_time' => '11:00', 'multiplier' => 3],
    ]);

    expect(fn () => app(PublishPromotion::class)->handleSubmitted($owner, $invalid, 'UTC', $draft))
        ->toThrow(ValidationException::class);

    expect($draft->fresh()->toArray())->toBe($before)
        ->and($draft->extraPoints()->orderBy('id')->get()->toArray())->toBe($beforeRules);
});

it('leaves an existing draft unchanged when submitted fields are invalid', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $startDate = submittedPublicationFutureDate('UTC');
    $draft = app(SavePromotionDraft::class)->handle($owner, submittedPublicationInput($startDate, 'Saved draft'));
    $before = $draft->fresh()->toArray();
    $invalid = submittedPublicationInput($startDate, 'Must not persist');
    $invalid['target_points'] = 0;

    expect(fn () => app(PublishPromotion::class)->handleSubmitted($owner, $invalid, 'UTC', $draft))
        ->toThrow(ValidationException::class);

    expect($draft->fresh()->toArray())->toBe($before);
});

it('uses one post-lock clock instant to reject a stale submitted start date without changing a draft', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $startDate = submittedPublicationFutureDate('UTC');
    $draft = app(SavePromotionDraft::class)->handle($owner, submittedPublicationInput($startDate, 'Saved draft'));
    $before = $draft->fresh()->toArray();
    $clock = mock(DatabaseClock::class);
    $clock->shouldReceive('captureForBusinessTimezone')->once()->with('UTC')->andReturn([
        'instant' => (string) DB::scalar('SELECT clock_timestamp()'),
        'business_date' => CarbonImmutable::parse($startDate)->addDay()->toDateString(),
    ]);
    $this->instance(DatabaseClock::class, $clock);

    expect(fn () => app(PublishPromotion::class)->handleSubmitted(
        $owner,
        submittedPublicationInput($startDate, 'Must not publish'),
        'UTC',
        $draft,
    ))->toThrow(ValidationException::class);

    expect($draft->fresh()->toArray())->toBe($before);
});

it('does not publish submitted values when the reviewed timezone no longer matches', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $startDate = submittedPublicationFutureDate('America/La_Paz');
    $draft = app(SavePromotionDraft::class)->handle($owner, submittedPublicationInput($startDate, 'Saved draft'));
    $before = $draft->fresh()->toArray();
    $business->update(['timezone' => 'UTC']);

    expect(fn () => app(PublishPromotion::class)->handleSubmitted(
        $owner,
        submittedPublicationInput($startDate, 'Unconfirmed terms'),
        'America/La_Paz',
        $draft,
    ))->toThrow(ValidationException::class);

    expect($draft->fresh()->toArray())->toBe($before);
});

it('rejects a submitted window overlapping published occupancy without saving a new aggregate', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $startDate = submittedPublicationFutureDate('UTC');
    $start = $startDate.' 00:00:00+00';
    $end = CarbonImmutable::parse($startDate)->addDay()->toDateString().' 00:00:00+00';
    DB::table('promotions')->insert([
        'public_id' => (string) Str::uuid(),
        'business_id' => $business->id,
        'target_points' => 8,
        'reward_title' => 'Existing publication',
        'status' => PromotionStatus::Published->value,
        'timezone_snapshot' => 'UTC',
        'starts_at' => $start,
        'ends_at' => $end,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(PublishPromotion::class)->handleSubmitted(
        $owner,
        submittedPublicationInput($startDate),
        'UTC',
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('promotions', 1);
});

it('leaves an existing draft unchanged when its submitted window overlaps published occupancy', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $startDate = submittedPublicationFutureDate('UTC');
    $draft = app(SavePromotionDraft::class)->handle($owner, submittedPublicationInput($startDate, 'Saved draft'));
    $before = $draft->fresh()->toArray();
    $beforeRules = $draft->extraPoints()->orderBy('id')->get()->toArray();
    $start = $startDate.' 00:00:00+00';
    $end = CarbonImmutable::parse($startDate)->addDay()->toDateString().' 00:00:00+00';
    DB::table('promotions')->insert([
        'public_id' => (string) Str::uuid(),
        'business_id' => $business->id,
        'target_points' => 8,
        'reward_title' => 'Existing publication',
        'status' => PromotionStatus::Published->value,
        'timezone_snapshot' => 'UTC',
        'starts_at' => $start,
        'ends_at' => $end,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(PublishPromotion::class)->handleSubmitted(
        $owner,
        submittedPublicationInput($startDate, 'Must not publish'),
        'UTC',
        $draft,
    ))->toThrow(ValidationException::class);

    expect($draft->fresh()->toArray())->toBe($before)
        ->and($draft->extraPoints()->orderBy('id')->get()->toArray())->toBe($beforeRules);

    $this->assertDatabaseCount('promotions', 2);
});

it('rejects submitted publication for a non-owned or already-published promotion', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $otherOwner = User::factory()->create();
    Business::factory()->for($otherOwner)->create(['timezone' => 'UTC']);
    $startDate = submittedPublicationFutureDate('UTC');
    $draft = app(SavePromotionDraft::class)->handle($owner, submittedPublicationInput($startDate));

    expect(fn () => app(PublishPromotion::class)->handleSubmitted(
        $otherOwner,
        submittedPublicationInput($startDate),
        'UTC',
        $draft,
    ))->toThrow(ModelNotFoundException::class);

    app(PublishPromotion::class)->handle($owner, $draft, 'UTC');

    expect(fn () => app(PublishPromotion::class)->handleSubmitted(
        $owner,
        submittedPublicationInput($startDate),
        'UTC',
        $draft,
    ))->toThrow(ValidationException::class);
});

/**
 * Return a Promotion start date safely after the current Business-local date.
 *
 * @param  string  $timezone  IANA timezone used to query today's Business-local calendar date.
 * @return string Future ISO calendar date suitable for publication tests.
 */
function submittedPublicationFutureDate(string $timezone): string
{
    return CarbonImmutable::parse((string) DB::scalar(
        'SELECT (clock_timestamp() AT TIME ZONE ?)::date',
        [$timezone],
    ))->addDays(7)->toDateString();
}

/**
 * Build one complete untrusted submitted aggregate for publication tests.
 *
 * @param  string  $startDate  ISO local start date for the submitted Promotion.
 * @param  string  $title  Reward title included in the submitted terms.
 * @param  list<array{weekday: int, start_time: string|null, end_time: string|null, multiplier: int}>  $windows  Multiplier windows included in the submitted aggregate.
 * @return array{local_start_date: string, local_end_date: string, target_points: int, reward_title: string, reward_description: string, extra_points: list<array{weekday: int, start_time: string|null, end_time: string|null, multiplier: int}>} Submitted Promotion fields and child windows.
 */
function submittedPublicationInput(string $startDate, string $title = 'Submitted reward', array $windows = []): array
{
    return [
        'local_start_date' => $startDate,
        'local_end_date' => CarbonImmutable::parse($startDate)->addDays(6)->toDateString(),
        'target_points' => 12,
        'reward_title' => $title,
        'reward_description' => 'A submitted description.',
        'extra_points' => $windows,
    ];
}
