<?php

use App\Actions\Promotions\SavePromotionDraft;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\PromotionMultiplierWindow;
use App\Models\User;
use App\Support\DatabaseClock;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $clock = Mockery::mock(DatabaseClock::class);
    $clock->shouldReceive('captureForBusinessTimezone')->andReturn([
        'instant' => '2026-10-07 12:00:00+00',
        'business_date' => '2026-10-07',
    ]);
    $this->instance(DatabaseClock::class, $clock);
});

it('rejects a past Business-local start date before creating any records', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $clock = Mockery::mock(DatabaseClock::class);
    $clock->shouldReceive('captureForBusinessTimezone')->once()
        ->with('America/La_Paz')
        ->andReturn(['instant' => '2026-10-07 12:00:00+00', 'business_date' => '2026-10-07']);
    $this->instance(DatabaseClock::class, $clock);

    expect(fn () => app(SavePromotionDraft::class)->handle($owner, promotionDraftInput([
        'local_start_date' => '2026-10-06',
        'local_end_date' => '2026-10-07',
    ])))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('promotions', 0);
    $this->assertDatabaseCount('promotion_multiplier_windows', 0);
});

it('accepts a draft starting on the current Business-local date', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['timezone' => 'Asia/Kolkata']);
    $clock = Mockery::mock(DatabaseClock::class);
    $clock->shouldReceive('captureForBusinessTimezone')->once()
        ->with('Asia/Kolkata')
        ->andReturn(['instant' => '2026-10-07 12:00:00+00', 'business_date' => '2026-10-07']);
    $this->instance(DatabaseClock::class, $clock);

    $promotion = app(SavePromotionDraft::class)->handle($owner, promotionDraftInput([
        'local_start_date' => '2026-10-07',
        'local_end_date' => '2026-10-07',
    ]));

    expect($promotion->local_start_date->toDateString())->toBe('2026-10-07')
        ->and($promotion->local_end_date->toDateString())->toBe('2026-10-07');
});

it('does not save a stale draft after its Business-local start date passes', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    $clock = Mockery::mock(DatabaseClock::class);
    $clock->shouldReceive('captureForBusinessTimezone')->twice()
        ->with('Europe/Madrid')
        ->andReturn(
            ['instant' => '2026-10-07 12:00:00+00', 'business_date' => '2026-10-07'],
            ['instant' => '2026-10-09 12:00:00+00', 'business_date' => '2026-10-09'],
        );
    $this->instance(DatabaseClock::class, $clock);
    $action = app(SavePromotionDraft::class);
    $promotion = $action->handle($owner, promotionDraftInput([
        'local_start_date' => '2026-10-08',
        'local_end_date' => '2026-10-14',
        'extra_points' => [promotionWindow(1, '09:00', '10:00', 2)],
    ]));
    $previousUpdatedAt = $promotion->updated_at;

    expect(fn () => $action->handle($owner, promotionDraftInput([
        'reward_title' => 'Must remain unchanged',
        'local_start_date' => '2026-10-08',
        'local_end_date' => '2026-10-14',
        'extra_points' => [],
    ]), $promotion))->toThrow(ValidationException::class);

    expect($promotion->fresh()->reward_title)->toBe('A coffee with pastry')
        ->and($promotion->fresh()->updated_at->equalTo($previousUpdatedAt))->toBeTrue();
    $this->assertDatabaseCount('promotion_multiplier_windows', 1);
});

it('revalidates an existing draft using the Business timezone that is current at save time', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $clock = Mockery::mock(DatabaseClock::class);
    $clock->shouldReceive('captureForBusinessTimezone')->once()
        ->with('America/La_Paz')
        ->andReturn(['instant' => '2026-10-07 12:00:00+00', 'business_date' => '2026-10-07']);
    $clock->shouldReceive('captureForBusinessTimezone')->once()
        ->with('Europe/Madrid')
        ->andReturn(['instant' => '2026-10-09 12:00:00+00', 'business_date' => '2026-10-09']);
    $this->instance(DatabaseClock::class, $clock);
    $action = app(SavePromotionDraft::class);
    $promotion = $action->handle($owner, promotionDraftInput([
        'local_start_date' => '2026-10-08',
        'local_end_date' => '2026-10-14',
    ]));
    $business->update(['timezone' => 'Europe/Madrid']);

    expect(fn () => $action->handle($owner, promotionDraftInput([
        'local_start_date' => '2026-10-08',
        'local_end_date' => '2026-10-14',
    ]), $promotion))->toThrow(ValidationException::class);

    expect($promotion->fresh()->local_start_date->toDateString())->toBe('2026-10-08');
});

it('reports missing stored timed-window fields separately', function (array $window, string $errorKey) {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    try {
        app(SavePromotionDraft::class)->handle($owner, promotionDraftInput([
            'extra_points' => [$window],
        ]));

        $this->fail('An incomplete timed window was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($errorKey)
            ->and($exception->errors())->not->toHaveKey('extra_points');
    }
})->with([
    'end time is required when start is present' => [promotionWindow(1, '09:00', null, 2), 'extra_points.0.end_time'],
    'start time is required when end is present' => [promotionWindow(1, null, '10:00', 2), 'extra_points.0.start_time'],
]);

it('captures one PostgreSQL clock reading after locking the Business and Promotion', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    $this->app->forgetInstance(DatabaseClock::class);
    $clock = app(DatabaseClock::class);
    $today = $clock->captureForBusinessTimezone($business->timezone)['business_date'];
    $start = (new DateTimeImmutable($today, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    $end = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d');
    $promotion = app(SavePromotionDraft::class)->handle($owner, promotionDraftInput([
        'local_start_date' => $start,
        'local_end_date' => $end,
    ]));
    $operationQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$operationQueries): void {
        if (str_contains(strtolower($query->sql), 'for update') || str_contains($query->sql, 'clock_timestamp()')) {
            $operationQueries[] = $query->sql;
        }
    });

    app(SavePromotionDraft::class)->handle($owner, promotionDraftInput([
        'local_start_date' => $start,
        'local_end_date' => $end,
        'reward_title' => 'Updated after current locks',
    ]), $promotion);

    $clockIndexes = array_keys(array_filter($operationQueries, static fn (string $sql): bool => str_contains($sql, 'clock_timestamp()')));
    $lockIndexes = array_keys(array_filter($operationQueries, static fn (string $sql): bool => str_contains(strtolower($sql), 'for update')));

    expect($clockIndexes)->toHaveCount(1)
        ->and($lockIndexes)->toHaveCount(2)
        ->and(max($lockIndexes))->toBeLessThan($clockIndexes[0]);
});

it('creates independent drafts owned by the authenticated business and stores local dates', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $action = app(SavePromotionDraft::class);

    $first = $action->handle($owner, promotionDraftInput([
        'extra_points' => [promotionWindow(1, '09:00', '10:00', 2)],
        'business_id' => 99999,
        'status' => 'published',
    ]));
    $second = $action->handle($owner, promotionDraftInput());

    expect($first->business->is($business))->toBeTrue()
        ->and($first->status)->toBe(PromotionStatus::Draft)
        ->and($first->public_id)->not->toBeEmpty()
        ->and($first->local_start_date->toDateString())->toBe('2026-11-01')
        ->and($first->local_end_date->toDateString())->toBe('2026-11-07')
        ->and($first->timezone)->toBeNull()
        ->and($second->extraPoints)->toBeEmpty();
    $this->assertDatabaseCount('promotions', 2);
    $this->assertDatabaseCount('promotion_multiplier_windows', 1);
});

it('updates a complete draft aggregate without changing its business owner', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $otherOwner = User::factory()->create();
    $otherBusiness = Business::factory()->for($otherOwner)->create();
    $promotion = app(SavePromotionDraft::class)->handle($owner, promotionDraftInput([
        'extra_points' => [promotionWindow(1, '09:00', '10:00', 2)],
    ]));

    $updated = app(SavePromotionDraft::class)->handle($owner, promotionDraftInput([
        'reward_title' => 'A different reward',
        'extra_points' => [promotionWindow(1, '10:00', '11:00', 5)],
        'business_id' => $otherBusiness->id,
    ]), $promotion);

    expect($updated->is($promotion))->toBeTrue()
        ->and($updated->business_id)->toBe($business->id)
        ->and($updated->reward_title)->toBe('A different reward')
        ->and($updated->extraPoints)->toHaveCount(1)
        ->and(substr($updated->extraPoints->first()->start_time, 0, 5))->toBe('10:00')
        ->and($updated->extraPoints->first()->multiplier)->toBe(5);
    $this->assertDatabaseCount('promotions', 1);
    $this->assertDatabaseCount('promotion_multiplier_windows', 1);
});

it('refuses to edit a promotion owned by another business', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $otherOwner = User::factory()->create();
    Business::factory()->for($otherOwner)->create();
    $promotion = app(SavePromotionDraft::class)->handle($otherOwner, promotionDraftInput());

    expect(fn () => app(SavePromotionDraft::class)->handle($owner, promotionDraftInput(), $promotion))
        ->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseHas('promotions', ['id' => $promotion->id, 'business_id' => $promotion->business_id]);
    expect($promotion->fresh()->business_id)->not->toBe($business->id);
});

it('refuses to edit a promotion after it leaves draft status', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();
    $promotion = app(SavePromotionDraft::class)->handle($owner, promotionDraftInput());
    DB::table('promotions')->where('id', $promotion->id)->update([
        'local_start_date' => null,
        'local_end_date' => null,
        'status' => 'published',
        'timezone_snapshot' => 'America/La_Paz',
        'starts_at' => '2026-11-01 04:00:00+00',
        'ends_at' => '2026-11-08 04:00:00+00',
    ]);

    expect(fn () => app(SavePromotionDraft::class)->handle($owner, promotionDraftInput([
        'reward_title' => 'Must not be saved',
    ]), $promotion))->toThrow(ValidationException::class);

    expect($promotion->fresh()->reward_title)->toBe('A coffee with pastry');
});

it('rejects invalid promotion fields without creating a parent record', function (array $changes, string $field) {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    try {
        app(SavePromotionDraft::class)->handle($owner, promotionDraftInput($changes));
        $this->fail('Invalid Promotion draft input was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field)
            ->and($exception->errors()[$field])->not->toBeEmpty();
    }

    $this->assertDatabaseCount('promotions', 0);
    $this->assertDatabaseCount('promotion_multiplier_windows', 0);
})->with([
    'missing title' => [['reward_title' => ''], 'reward_title'],
    'non-positive target' => [['target_points' => 0], 'target_points'],
    'missing start date' => [['local_start_date' => null], 'local_start_date'],
    'reversed dates' => [['local_start_date' => '2026-11-08'], 'local_end_date'],
]);

it('rejects invalid extra-point rules without changing an existing draft', function (array $windows) {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();
    $action = app(SavePromotionDraft::class);
    $promotion = $action->handle($owner, promotionDraftInput([
        'extra_points' => [promotionWindow(1, '09:00', '10:00', 2)],
    ]));
    $originalUpdatedAt = $promotion->updated_at;

    expect(fn () => $action->handle($owner, promotionDraftInput([
        'reward_title' => 'Must roll back',
        'extra_points' => $windows,
    ]), $promotion))->toThrow(ValidationException::class);

    expect($promotion->fresh()->reward_title)->toBe('A coffee with pastry')
        ->and($promotion->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
    $this->assertDatabaseCount('promotion_multiplier_windows', 1);
    $this->assertDatabaseHas('promotion_multiplier_windows', [
        'promotion_id' => $promotion->id,
        'weekday' => 1,
        'start_time' => '09:00:00',
        'multiplier' => 2,
    ]);
})->with([
    'unsupported multiplier' => [[promotionWindow(1, '09:00', '10:00', 4)]],
    'overlapping windows' => [[
        promotionWindow(1, '09:00', '10:30', 2),
        promotionWindow(1, '10:00', '11:00', 3),
    ]],
    'whole day conflicts with timed' => [[
        promotionWindow(1, null, null, 2),
        promotionWindow(1, '09:00', '10:00', 3),
    ]],
    'overnight window' => [[promotionWindow(1, '23:00', '01:00', 2)]],
    'duplicate whole day' => [[
        promotionWindow(1, null, null, 2),
        promotionWindow(1, null, null, 5),
    ]],
]);

it('allows disjoint and touching half-open windows on the same weekday', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    $promotion = app(SavePromotionDraft::class)->handle($owner, promotionDraftInput([
        'extra_points' => [
            promotionWindow(1, '09:00', '10:00', 2),
            promotionWindow(1, '10:00', '11:00', 3),
            promotionWindow(1, '13:00', '14:00', 5),
        ],
    ]));

    expect($promotion->extraPoints)->toHaveCount(3);
});

it('rolls back promotion edits and rule replacement when a child write fails', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();
    $action = app(SavePromotionDraft::class);
    $promotion = $action->handle($owner, promotionDraftInput([
        'extra_points' => [promotionWindow(1, '09:00', '10:00', 2)],
    ]));
    $dispatcher = PromotionMultiplierWindow::getEventDispatcher();
    PromotionMultiplierWindow::setEventDispatcher(clone $dispatcher);
    PromotionMultiplierWindow::creating(function (): never {
        throw new RuntimeException('Multiplier persistence failed.');
    });

    try {
        expect(fn () => $action->handle($owner, promotionDraftInput([
            'reward_title' => 'Must roll back',
            'extra_points' => [promotionWindow(1, '10:00', '11:00', 5)],
        ]), $promotion))->toThrow(RuntimeException::class);
    } finally {
        PromotionMultiplierWindow::setEventDispatcher($dispatcher);
    }

    expect($promotion->fresh()->reward_title)->toBe('A coffee with pastry');
    $this->assertDatabaseCount('promotion_multiplier_windows', 1);
    $this->assertDatabaseHas('promotion_multiplier_windows', [
        'promotion_id' => $promotion->id,
        'start_time' => '09:00:00',
        'multiplier' => 2,
    ]);
});

/**
 * Builds a valid default draft payload with optional field overrides.
 *
 * @param  array<string, mixed>  $overrides  Draft field values replacing the valid defaults.
 * @return array<string, mixed> Draft fields and child rule entries ready for action validation.
 */
function promotionDraftInput(array $overrides = []): array
{
    return array_replace([
        'local_start_date' => '2026-11-01',
        'local_end_date' => '2026-11-07',
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
        'reward_description' => 'Any small coffee and pastry.',
        'extra_points' => [],
    ], $overrides);
}

/**
 * Builds one multiplier-window entry for action and persistence tests.
 *
 * @param  int  $weekday  ISO weekday from 1 (Monday) through 7 (Sunday).
 * @param  string|null  $start  Window start in HH:MM format, or null for all day.
 * @param  string|null  $end  Window end in HH:MM format, or null for all day.
 * @param  int  $multiplier  Supported total multiplier value.
 * @return array{weekday: int, start_time: string|null, end_time: string|null, multiplier: int} Complete rule entry.
 */
function promotionWindow(int $weekday, ?string $start, ?string $end, int $multiplier): array
{
    return [
        'weekday' => $weekday,
        'start_time' => $start,
        'end_time' => $end,
        'multiplier' => $multiplier,
    ];
}
