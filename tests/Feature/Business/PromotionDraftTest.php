<?php

use App\Actions\Promotions\SavePromotionDraft;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\PromotionMultiplierWindow;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

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
    DB::table('promotions')->where('id', $promotion->id)->update(['status' => 'published']);

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

/** @return array<string, mixed> */
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

/** @return array{weekday: int, start_time: ?string, end_time: ?string, multiplier: int} */
function promotionWindow(int $weekday, ?string $start, ?string $end, int $multiplier): array
{
    return [
        'weekday' => $weekday,
        'start_time' => $start,
        'end_time' => $end,
        'multiplier' => $multiplier,
    ];
}
