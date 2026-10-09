<?php

use App\Models\Business;
use App\Models\Promotion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('persists a published UTC window without draft dates', function () {
    $business = Business::factory()->create();
    $promotionId = DB::table('promotions')->insertGetId([
        'public_id' => '00000000-0000-4000-8000-000000000002',
        'business_id' => $business->id,
        'local_start_date' => null,
        'local_end_date' => null,
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
        'reward_description' => 'Any small coffee and pastry.',
        'status' => 'published',
        'timezone_snapshot' => 'America/La_Paz',
        'starts_at' => '2030-01-01 04:00:00+00',
        'ends_at' => '2030-01-08 04:00:00+00',
        'cancelled_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $promotion = Promotion::query()->findOrFail($promotionId);

    expect($promotion->local_start_date)->toBeNull()
        ->and($promotion->local_end_date)->toBeNull()
        ->and($promotion->starts_at)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($promotion->ends_at)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($promotion->cancelled_at)->toBeNull();
});

it('rejects incomplete or mixed publication facts when writes bypass application validation', function (array $changes) {
    $promotion = publishedPromotionForPersistenceConstraints();

    expect(fn () => DB::table('promotions')->where('id', $promotion->id)->update($changes))
        ->toThrow(QueryException::class);
})->with([
    'missing timezone snapshot' => [['timezone_snapshot' => null]],
    'missing UTC start' => [['starts_at' => null]],
    'missing UTC end' => [['ends_at' => null]],
    'published with draft dates' => [['local_start_date' => '2030-01-01']],
    'draft with published facts' => [['status' => 'draft']],
    'zero-length window' => [['ends_at' => '2030-01-01 04:00:00+00']],
    'reversed window' => [['ends_at' => '2029-12-31 04:00:00+00']],
    'cancelled without cancellation instant' => [['status' => 'cancelled']],
]);

it('persists cancellation before the published window starts', function () {
    $business = Business::factory()->create();
    $promotionId = DB::table('promotions')->insertGetId([
        'public_id' => '00000000-0000-4000-8000-000000000003',
        'business_id' => $business->id,
        'local_start_date' => null,
        'local_end_date' => null,
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
        'status' => 'cancelled',
        'timezone_snapshot' => 'America/La_Paz',
        'starts_at' => '2030-01-01 04:00:00+00',
        'ends_at' => '2030-01-08 04:00:00+00',
        'cancelled_at' => '2029-12-31 12:00:00+00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $promotion = Promotion::query()->findOrFail($promotionId);

    expect($promotion->status->value)->toBe('cancelled')
        ->and($promotion->cancelled_at)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($promotion->cancelled_at->toIso8601String())->toBe('2029-12-31T12:00:00+00:00');
});

it('rejects invalid promotion windows when writes bypass application validation', function (string $sql) {
    $promotion = promotionForPersistenceConstraints();

    expect(fn () => DB::statement($sql, [$promotion->id]))->toThrow(QueryException::class);
})->with([
    'weekday outside ISO range' => ['INSERT INTO promotion_multiplier_windows (promotion_id, weekday, multiplier, created_at, updated_at) VALUES (?, 0, 2, NOW(), NOW())'],
    'unsupported multiplier' => ['INSERT INTO promotion_multiplier_windows (promotion_id, weekday, multiplier, created_at, updated_at) VALUES (?, 1, 4, NOW(), NOW())'],
    'incomplete time range' => ["INSERT INTO promotion_multiplier_windows (promotion_id, weekday, multiplier, start_time, created_at, updated_at) VALUES (?, 1, 2, '09:00', NOW(), NOW())"],
]);

it('rejects invalid promotion rows when writes bypass application validation', function (string $sql) {
    $promotion = promotionForPersistenceConstraints();

    expect(fn () => DB::statement($sql, [$promotion->id]))->toThrow(QueryException::class);
})->with([
    'non-positive target' => ['UPDATE promotions SET target_points = 0 WHERE id = ?'],
    'reversed dates' => ["UPDATE promotions SET local_start_date = '2026-11-08' WHERE id = ?"],
    'unsupported status' => ["UPDATE promotions SET status = 'scheduled' WHERE id = ?"],
]);

it('rejects duplicate public promotion identifiers in the database', function () {
    $firstBusiness = Business::factory()->create();
    $secondBusiness = Business::factory()->create();
    $promotion = $firstBusiness->promotions()->create([
        'local_start_date' => '2026-11-01',
        'local_end_date' => '2026-11-07',
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
    ]);

    expect(fn () => DB::table('promotions')->insert([
        'public_id' => $promotion->public_id,
        'business_id' => $secondBusiness->id,
        'local_start_date' => '2026-11-01',
        'local_end_date' => '2026-11-07',
        'target_points' => 8,
        'reward_title' => 'Another reward',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects promotions and multiplier windows without their owning records', function (string $table, array $attributes) {
    expect(fn () => DB::table($table)->insert($attributes))->toThrow(QueryException::class);
})->with([
    'promotion without business' => ['promotions', [
        'public_id' => '00000000-0000-4000-8000-000000000001',
        'business_id' => 999999,
        'local_start_date' => '2026-11-01',
        'local_end_date' => '2026-11-07',
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]],
    'window without promotion' => ['promotion_multiplier_windows', [
        'promotion_id' => 999999,
        'weekday' => 1,
        'multiplier' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]],
]);

/**
 * Creates a persisted draft used to exercise database-level constraints.
 *
 * @return Promotion Existing draft row with valid default dates and reward fields.
 */
function promotionForPersistenceConstraints(): Promotion
{
    return Business::factory()->create()->promotions()->create([
        'local_start_date' => '2026-11-01',
        'local_end_date' => '2026-11-07',
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
    ]);
}

/**
 * Creates a persisted published Promotion used to exercise database-level publication constraints.
 *
 * @return Promotion Existing published row with complete frozen window facts.
 */
function publishedPromotionForPersistenceConstraints(): Promotion
{
    $business = Business::factory()->create();
    $promotionId = DB::table('promotions')->insertGetId([
        'public_id' => '00000000-0000-4000-8000-000000000004',
        'business_id' => $business->id,
        'local_start_date' => null,
        'local_end_date' => null,
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
        'status' => 'published',
        'timezone_snapshot' => 'America/La_Paz',
        'starts_at' => '2030-01-01 04:00:00+00',
        'ends_at' => '2030-01-08 04:00:00+00',
        'cancelled_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Promotion::query()->findOrFail($promotionId);
}
