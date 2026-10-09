<?php

use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Models\PromotionParticipation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reuses one anonymous pass across Promotions and reads parent-scoped participation records', function () {
    $business = Business::factory()->create();
    $pass = $business->customerPasses()->create();
    $firstPromotion = promotionForParticipation($business);
    $secondPromotion = promotionForParticipation($business);
    $otherBusiness = Business::factory()->create();
    $otherPass = $otherBusiness->customerPasses()->create();
    $otherPromotion = promotionForParticipation($otherBusiness);

    $first = participationForPersistence($pass, $firstPromotion);
    $second = participationForPersistence($pass, $secondPromotion);
    $other = participationForPersistence($otherPass, $otherPromotion);

    $this->assertModelExists($first);
    expect($first->business->is($business))->toBeTrue();
    expect($first->customerPass->is($pass))->toBeTrue();
    expect($first->promotion->is($firstPromotion))->toBeTrue();
    expect($business->promotionParticipations()->orderBy('id')->get()->modelKeys())->toBe([$first->id, $second->id]);
    expect($pass->promotionParticipations()->orderBy('id')->get()->modelKeys())->toBe([$first->id, $second->id]);
    expect($firstPromotion->participations->modelKeys())->toBe([$first->id]);
    expect($secondPromotion->participations->modelKeys())->toBe([$second->id]);
    expect($otherBusiness->promotionParticipations->modelKeys())->toBe([$other->id]);
    expect($otherPass->promotionParticipations->modelKeys())->toBe([$other->id]);
    expect($otherPromotion->participations->modelKeys())->toBe([$other->id]);
    $this->assertDatabaseCount('customer_passes', 2);
});

it('rejects duplicate pass and Promotion associations through direct inserts', function () {
    $pass = CustomerPass::factory()->create();
    $promotion = promotionForParticipation($pass->business);
    participationForPersistence($pass, $promotion);

    expect(fn () => DB::table('promotion_participations')->insert([
        'business_id' => $pass->business_id,
        'customer_pass_id' => $pass->id,
        'promotion_id' => $promotion->id,
    ]))->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23505'));
});

it('rejects updates that duplicate an existing pass and Promotion association', function () {
    $pass = CustomerPass::factory()->create();
    $firstPromotion = promotionForParticipation($pass->business);
    participationForPersistence($pass, $firstPromotion);
    $second = participationForPersistence($pass, promotionForParticipation($pass->business));

    expect(fn () => DB::table('promotion_participations')->where('id', $second->id)
        ->update(['promotion_id' => $firstPromotion->id]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23505'));
});

it('rejects missing association parents through direct inserts', function (string $column, ?int $value, string $sqlState) {
    $pass = CustomerPass::factory()->create();
    $promotion = promotionForParticipation($pass->business);
    $attributes = [
        'business_id' => $pass->business_id,
        'customer_pass_id' => $pass->id,
        'promotion_id' => $promotion->id,
    ];
    $attributes[$column] = $value;

    expect(fn () => DB::table('promotion_participations')->insert($attributes))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe($sqlState));
})->with(['business_id', 'customer_pass_id', 'promotion_id'])
    ->with(['null' => [null, '23502'], 'nonexistent' => [999999, '23503']]);

it('rejects cross-Business parents through direct inserts', function (string $column) {
    $pass = CustomerPass::factory()->create();
    $promotion = promotionForParticipation($pass->business);
    $foreignPass = CustomerPass::factory()->create();
    $foreignPromotion = promotionForParticipation($foreignPass->business);
    $attributes = [
        'business_id' => $pass->business_id,
        'customer_pass_id' => $pass->id,
        'promotion_id' => $promotion->id,
    ];
    $attributes[$column] = $column === 'customer_pass_id' ? $foreignPass->id : $foreignPromotion->id;

    expect(fn () => DB::table('promotion_participations')->insert($attributes))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
})->with(['customer_pass_id', 'promotion_id']);

it('rejects cross-Business changes through direct association updates', function (string $column) {
    $pass = CustomerPass::factory()->create();
    $participation = participationForPersistence($pass, promotionForParticipation($pass->business));
    $foreignPass = CustomerPass::factory()->create();
    $foreignPromotion = promotionForParticipation($foreignPass->business);
    $foreignIds = [
        'business_id' => $foreignPass->business_id,
        'customer_pass_id' => $foreignPass->id,
        'promotion_id' => $foreignPromotion->id,
    ];

    expect(fn () => DB::table('promotion_participations')->where('id', $participation->id)
        ->update([$column => $foreignIds[$column]]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
})->with(['business_id', 'customer_pass_id', 'promotion_id']);

it('rejects moving referenced parents to another Business through direct updates', function (string $table, string $foreignKey) {
    $pass = CustomerPass::factory()->create();
    $participation = participationForPersistence($pass, promotionForParticipation($pass->business));
    $otherBusiness = Business::factory()->create();

    expect(fn () => DB::table($table)->where('id', $participation->$foreignKey)
        ->update(['business_id' => $otherBusiness->id]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
})->with(['pass' => ['customer_passes', 'customer_pass_id'], 'Promotion' => ['promotions', 'promotion_id']]);

it('restricts deletion of parents with participation records', function (string $table, string $foreignKey) {
    $pass = CustomerPass::factory()->create();
    $participation = participationForPersistence($pass, promotionForParticipation($pass->business));

    expect(fn () => DB::table($table)->where('id', $participation->$foreignKey)->delete())
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
})->with([
    'pass' => ['customer_passes', 'customer_pass_id'],
    'Promotion' => ['promotions', 'promotion_id'],
    'Business' => ['businesses', 'business_id'],
]);

it('rolls back participation before parent keys and reapplies without losing parent identities', function () {
    $pass = CustomerPass::factory()->create();
    $promotion = promotionForParticipation($pass->business);
    participationForPersistence($pass, $promotion);
    $migration = require glob(database_path('migrations/*_create_promotion_participations_table.php'))[0];
    $visitsMigration = require database_path('migrations/2026_10_09_200000_create_visits_table.php');
    $entitlementsMigration = require database_path('migrations/2026_10_09_200001_create_reward_entitlements_table.php');

    $entitlementsMigration->down();
    $visitsMigration->down();
    $migration->down();
    $migration->up();
    $visitsMigration->up();
    $entitlementsMigration->up();

    $this->assertModelExists($pass);
    $this->assertModelExists($promotion);
    $this->assertDatabaseCount('promotion_participations', 0);
    $this->assertModelExists(participationForPersistence($pass, $promotion));
});

/**
 * Creates a valid draft Promotion for association persistence tests.
 *
 * @param  Business  $business  Business that owns the draft.
 * @return Promotion Persisted Promotion with valid draft terms.
 */
function promotionForParticipation(Business $business): Promotion
{
    return $business->promotions()->create([
        'local_start_date' => '2030-01-01',
        'local_end_date' => '2030-01-07',
        'target_points' => 8,
        'reward_title' => 'A coffee',
    ]);
}

/**
 * Persists only the association, without accepting a Visit or issuing credentials.
 *
 * @param  CustomerPass  $pass  Anonymous identity whose Business owns the association.
 * @param  Promotion  $promotion  Promotion to associate with the pass.
 * @return PromotionParticipation Persisted pass and Promotion association.
 */
function participationForPersistence(CustomerPass $pass, Promotion $promotion): PromotionParticipation
{
    $participation = $pass->business->promotionParticipations()->make();
    $participation->customerPass()->associate($pass);
    $participation->promotion()->associate($promotion);
    $participation->save();

    return $participation;
}
