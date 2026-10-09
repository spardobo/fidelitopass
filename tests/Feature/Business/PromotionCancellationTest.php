<?php

use App\Actions\Promotions\CancelPromotion;
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
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('cancels an active Promotion at one post-lock database instant and preserves its snapshot', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $today = (string) DB::scalar('SELECT (clock_timestamp() AT TIME ZONE ?)::date', [$business->timezone]);
    $promotion = cancellationPublishedPromotion($owner, $today, $today, [[
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '10:00',
        'multiplier' => 3,
    ]]);
    $before = $promotion->getRawOriginal();
    $rulesBefore = $promotion->extraPoints()->get()->toArray();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $cancelled = app(CancelPromotion::class)->handle($owner, $promotion);

    $lockIndexes = array_keys(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'for update')));
    $clockIndexes = array_keys(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'clock_timestamp()')));
    $after = $cancelled->fresh()->getRawOriginal();

    expect($cancelled->status)->toBe(PromotionStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->cancelled_at->equalTo($cancelled->updated_at))->toBeTrue()
        ->and($cancelled->starts_at->equalTo($promotion->starts_at))->toBeTrue()
        ->and($cancelled->ends_at->equalTo($promotion->ends_at))->toBeTrue()
        ->and($after['timezone_snapshot'])->toBe($before['timezone_snapshot'])
        ->and($after['target_points'])->toBe($before['target_points'])
        ->and($after['reward_title'])->toBe($before['reward_title'])
        ->and($after['reward_description'])->toBe($before['reward_description'])
        ->and($cancelled->fresh()->extraPoints()->get()->toArray())->toBe($rulesBefore)
        ->and($clockIndexes)->toHaveCount(1)
        ->and($lockIndexes)->toHaveCount(2)
        ->and(max($lockIndexes))->toBeLessThan($clockIndexes[0]);
});

it('rejects a draft without changing its state or local dates', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $startDate = cancellationFutureDate($business);
    $draft = app(SavePromotionDraft::class)->handle($owner, [
        'local_start_date' => $startDate,
        'local_end_date' => CarbonImmutable::parse($startDate)->addDay()->toDateString(),
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
        'reward_description' => 'Any small coffee and pastry.',
        'extra_points' => [],
    ]);
    $before = $draft->fresh()->getRawOriginal();

    $exception = cancellationValidationError(fn () => app(CancelPromotion::class)->handle($owner, $draft));

    expect($exception->errors()['promotion'])->toBe([__('business.promotion.cancel_only_published')]);

    expect($draft->fresh()->getRawOriginal())->toBe($before)
        ->and($business->promotions()->count())->toBe(1);
});

it('rejects an ended Promotion and leaves its historical facts unchanged', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $startDate = cancellationFutureDate($business);
    $promotion = cancellationPublishedPromotion($owner, $startDate, CarbonImmutable::parse($startDate)->addDay()->toDateString());
    $promotion->forceFill([
        'starts_at' => '2000-04-01 00:00:00+00',
        'ends_at' => '2000-04-03 00:00:00+00',
    ])->save();
    $before = $promotion->fresh()->getRawOriginal();

    $exception = cancellationValidationError(fn () => app(CancelPromotion::class)->handle($owner, $promotion));

    expect($exception->errors()['promotion'])->toBe([__('business.promotion.cancel_ended')]);

    expect($promotion->fresh()->getRawOriginal())->toBe($before)
        ->and($business->promotions()->count())->toBe(1);
});

it('rejects duplicate cancellation with localized feedback and preserves the first instant', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    $startDate = cancellationFutureDate($business);
    $promotion = cancellationPublishedPromotion($owner, $startDate, CarbonImmutable::parse($startDate)->addDay()->toDateString());
    $action = app(CancelPromotion::class);
    $cancelled = $action->handle($owner, $promotion);
    $firstInstant = $cancelled->cancelled_at->toIso8601String();
    $before = $cancelled->fresh()->getRawOriginal();

    $exception = cancellationValidationError(fn () => $action->handle($owner, $promotion));

    expect($exception->errors()['promotion'])->toBe([__('business.promotion.already_cancelled')]);

    expect($promotion->fresh()->cancelled_at->toIso8601String())->toBe($firstInstant)
        ->and($promotion->fresh()->getRawOriginal())->toBe($before);
});

it('does not reveal another Business promotion to an unrelated owner', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'UTC']);
    Business::factory()->for($otherOwner)->create(['timezone' => 'UTC']);
    $startDate = cancellationFutureDate($business);
    $promotion = cancellationPublishedPromotion($owner, $startDate, CarbonImmutable::parse($startDate)->addDay()->toDateString());
    $before = $promotion->fresh()->getRawOriginal();

    expect(fn () => app(CancelPromotion::class)->handle($otherOwner, $promotion))
        ->toThrow(ModelNotFoundException::class);

    expect($promotion->fresh()->getRawOriginal())->toBe($before);
});

it('rejects a same-day date-only replacement after active cancellation', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $today = (string) DB::scalar('SELECT (clock_timestamp() AT TIME ZONE ?)::date', [$business->timezone]);
    $active = cancellationPublishedPromotion($owner, $today, $today);
    app(CancelPromotion::class)->handle($owner, $active);
    $sameDay = cancellationDraft($owner, $today, $today);

    expect(fn () => app(PublishPromotion::class)->handle($owner, $sameDay, $business->timezone))
        ->toThrow(ValidationException::class, __('business.promotion.publication_window_overlaps'));

    expect($sameDay->fresh()->status)->toBe(PromotionStatus::Draft)
        ->and($sameDay->fresh()->starts_at)->toBeNull();
});

it('accepts a replacement at the next Business-local midnight after an active cancellation', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $today = (string) DB::scalar('SELECT (clock_timestamp() AT TIME ZONE ?)::date', [$business->timezone]);
    $active = cancellationPublishedPromotion($owner, $today, $today);
    app(CancelPromotion::class)->handle($owner, $active);
    $nextDate = (string) DB::scalar('SELECT (?::date + 1)::text', [$today]);
    $replacement = cancellationDraft($owner, $nextDate, $nextDate);

    $published = app(PublishPromotion::class)->handle($owner, $replacement, $business->timezone);
    $expectedStart = CarbonImmutable::parse($nextDate.' 00:00:00', $business->timezone)->utc();

    expect($published->status)->toBe(PromotionStatus::Published)
        ->and($published->starts_at->equalTo($expectedStart))->toBeTrue()
        ->and($active->fresh()->timezone_snapshot)->toBe('America/La_Paz');
});

it('releases a scheduled Promotion future window immediately after cancellation', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    $startDate = cancellationFutureDate($business);
    $endDate = CarbonImmutable::parse($startDate)->addDays(2)->toDateString();
    $scheduled = cancellationPublishedPromotion($owner, $startDate, $endDate);
    app(CancelPromotion::class)->handle($owner, $scheduled);
    $business->update(['timezone' => 'America/La_Paz']);
    $replacement = cancellationDraft($owner, $startDate, $endDate);

    $published = app(PublishPromotion::class)->handle($owner, $replacement, $business->timezone);

    expect($published->status)->toBe(PromotionStatus::Published)
        ->and($published->timezone_snapshot)->toBe('America/La_Paz')
        ->and($scheduled->fresh()->timezone_snapshot)->toBe('Europe/Madrid');
});

/**
 * Publishes one Promotion through the real action for cancellation fixtures.
 *
 * @param  User  $owner  Business owner publishing the fixture.
 * @param  string  $startDate  Inclusive Business-local start date in Y-m-d format.
 * @param  string  $endDate  Inclusive Business-local end date in Y-m-d format.
 * @param  list<array{weekday: int, start_time: string, end_time: string, multiplier: int}>  $windows  Frozen multiplier rules.
 * @return Promotion Published fixture with its original frozen terms and multiplier rules.
 */
function cancellationPublishedPromotion(User $owner, string $startDate, string $endDate, array $windows = []): Promotion
{
    $draft = cancellationDraft($owner, $startDate, $endDate, $windows);

    return app(PublishPromotion::class)->handle($owner, $draft, $owner->business->timezone);
}

/**
 * Creates one Promotion draft with complete terms and optional multiplier rules.
 *
 * @param  User  $owner  Business owner creating the draft fixture.
 * @param  string  $startDate  Inclusive Business-local start date in Y-m-d format.
 * @param  string  $endDate  Inclusive Business-local end date in Y-m-d format.
 * @param  list<array{weekday: int, start_time: string, end_time: string, multiplier: int}>  $windows  Draft multiplier rules.
 * @return Promotion Persisted draft with its complete terms and multiplier rules.
 */
function cancellationDraft(User $owner, string $startDate, string $endDate, array $windows = []): Promotion
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
 * Selects a date two Business-local days ahead using PostgreSQL's current calendar date.
 *
 * @param  Business  $business  Business whose current timezone defines the fixture date.
 * @return string Future Business-local calendar date in Y-m-d format.
 */
function cancellationFutureDate(Business $business): string
{
    return (string) DB::scalar(
        'SELECT ((clock_timestamp() AT TIME ZONE ?)::date + 2)::text',
        [$business->timezone],
    );
}

/**
 * Captures a rejected cancellation for assertions on its localized field message.
 *
 * @param  Closure(): mixed  $operation  Cancellation attempt expected to fail validation.
 * @return ValidationException Captured rejection whose localized field errors can be asserted.
 *
 * @throws RuntimeException When the cancellation unexpectedly succeeds.
 */
function cancellationValidationError(Closure $operation): ValidationException
{
    try {
        $operation();
    } catch (ValidationException $exception) {
        return $exception;
    }

    throw new RuntimeException('The cancellation was expected to be rejected.');
}
