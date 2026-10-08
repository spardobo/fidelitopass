<?php

use App\Models\Business;
use App\Models\Promotion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

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
 * Create a persisted draft used to exercise database-level constraints.
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
