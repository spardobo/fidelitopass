<?php

use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\RewardEntitlement;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('retains unlocked entitlements after final redemption with immutable precise domain instants', function () {
    $attributes = rewardAttributesForPersistence();
    $entitlement = new RewardEntitlement;
    $entitlement->forceFill($attributes)->save();

    $persisted = $entitlement->fresh();

    $this->assertModelExists($persisted);
    expect($persisted->business->id)->toBe($attributes['business_id']);
    expect($persisted->customerPass->id)->toBe($attributes['customer_pass_id']);
    expect($persisted->promotion->id)->toBe($attributes['promotion_id']);
    expect($persisted->unlocked_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($persisted->unlocked_at->utc()->format('Y-m-d H:i:s.u'))->toBe('2030-01-02 14:30:00.123456');
    expect($persisted->redeemed_at)->toBeNull();
    expect($persisted->redeemedBy)->toBeNull();

    $persisted->forceFill([
        'redeemed_at' => CarbonImmutable::parse('2030-01-03 10:15:00.654321-04:00'),
        'redeemed_by_user_id' => $persisted->business->user_id,
    ])->save();
    $redeemed = $persisted->fresh();

    expect($redeemed->redeemed_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($redeemed->redeemed_at->utc()->format('Y-m-d H:i:s.u'))->toBe('2030-01-03 14:15:00.654321');
    expect($redeemed->redeemedBy->id)->toBe($persisted->business->user_id);
    expect($redeemed->unlocked_at->utc()->format('Y-m-d H:i:s.u'))->toBe('2030-01-02 14:30:00.123456');
    expect(RewardEntitlement::query()->where('promotion_id', $attributes['promotion_id'])->count())->toBe(1);
});

it('preserves the unlock instant supplied as an immutable date with an offset', function () {
    $entitlement = new RewardEntitlement;
    $entitlement->forceFill([
        ...rewardAttributesForPersistence(),
        'unlocked_at' => CarbonImmutable::parse('2030-01-02 10:30:00.123456-04:00'),
    ])->save();

    expect($entitlement->fresh()->unlocked_at->utc()->format('Y-m-d H:i:s.u'))->toBe('2030-01-02 14:30:00.123456');
});

it('allows the same pass in later Promotions and different passes in the same Promotion', function () {
    $attributes = rewardAttributesForPersistence();
    DB::table('reward_entitlements')->insert($attributes);
    $otherPass = CustomerPass::factory()->for(Business::findOrFail($attributes['business_id']))->create();
    DB::table('reward_entitlements')->insert([...$attributes, 'customer_pass_id' => $otherPass->id]);
    $otherPromotion = $otherPass->business->promotions()->create([
        'local_start_date' => '2030-02-01',
        'local_end_date' => '2030-02-07',
        'target_points' => 8,
        'reward_title' => 'Another coffee',
    ]);

    DB::table('reward_entitlements')->insert([...$attributes, 'promotion_id' => $otherPromotion->id]);

    $this->assertDatabaseCount('reward_entitlements', 3);
});

it('rejects duplicate pass and Promotion entitlements through direct inserts', function () {
    $attributes = rewardAttributesForPersistence();
    DB::table('reward_entitlements')->insert($attributes);

    expect(fn () => DB::table('reward_entitlements')->insert($attributes))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23505'));
});

it('rejects updates that duplicate an entitlement for the same pass and Promotion', function () {
    $attributes = rewardAttributesForPersistence();
    DB::table('reward_entitlements')->insert($attributes);
    $otherPass = CustomerPass::factory()->for(Business::findOrFail($attributes['business_id']))->create();
    $id = DB::table('reward_entitlements')->insertGetId([...$attributes, 'customer_pass_id' => $otherPass->id]);

    expect(fn () => DB::table('reward_entitlements')->where('id', $id)->update(['customer_pass_id' => $attributes['customer_pass_id']]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23505'));
});

it('rejects missing entitlement parents through direct inserts', function (string $column, ?int $value, string $sqlState) {
    $attributes = rewardAttributesForPersistence();

    expect(fn () => DB::table('reward_entitlements')->insert([...$attributes, $column => $value]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe($sqlState));
})->with(['business_id', 'customer_pass_id', 'promotion_id'])
    ->with(['null' => [null, '23502'], 'nonexistent' => [999999, '23503']]);

it('rejects removing the required unlock instant through direct writes', function () {
    $attributes = rewardAttributesForPersistence();

    expect(fn () => DB::table('reward_entitlements')->insert([...$attributes, 'unlocked_at' => null]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23502'));
});

it('rejects missing parents and unlock instants through direct updates', function (string $column, mixed $value, string $sqlState) {
    $id = DB::table('reward_entitlements')->insertGetId(rewardAttributesForPersistence());

    expect(fn () => DB::table('reward_entitlements')->where('id', $id)->update([$column => $value]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe($sqlState));
})->with([
    'missing Business' => ['business_id', 999999, '23503'],
    'missing pass' => ['customer_pass_id', 999999, '23503'],
    'missing Promotion' => ['promotion_id', 999999, '23503'],
    'null Business' => ['business_id', null, '23502'],
    'null pass' => ['customer_pass_id', null, '23502'],
    'null Promotion' => ['promotion_id', null, '23502'],
    'null unlock' => ['unlocked_at', null, '23502'],
]);

it('rejects cross-Business entitlement parents through direct inserts', function (string $column) {
    $attributes = rewardAttributesForPersistence();
    $foreign = rewardAttributesForPersistence();

    expect(fn () => DB::table('reward_entitlements')->insert([...$attributes, $column => $foreign[$column]]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
})->with(['business_id', 'customer_pass_id', 'promotion_id']);

it('rejects cross-Business changes through direct entitlement updates', function (string $column) {
    $id = DB::table('reward_entitlements')->insertGetId(rewardAttributesForPersistence());
    $foreign = rewardAttributesForPersistence();

    expect(fn () => DB::table('reward_entitlements')->where('id', $id)->update([$column => $foreign[$column]]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
})->with(['business_id', 'customer_pass_id', 'promotion_id']);

it('rejects moving referenced entitlement parents to another Business', function (string $table, string $foreignKey) {
    $attributes = rewardAttributesForPersistence();
    DB::table('reward_entitlements')->insert($attributes);
    $otherBusiness = Business::factory()->create();

    expect(fn () => DB::table($table)->where('id', $attributes[$foreignKey])->update(['business_id' => $otherBusiness->id]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
})->with(['pass' => ['customer_passes', 'customer_pass_id'], 'Promotion' => ['promotions', 'promotion_id']]);

it('requires the redemption instant and actor together through direct inserts', function (string $missingColumn) {
    $attributes = redeemedRewardAttributesForPersistence();

    expect(fn () => DB::table('reward_entitlements')->insert([...$attributes, $missingColumn => null]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23514'));
})->with(['redeemed_at', 'redeemed_by_user_id']);

it('requires the redemption instant and actor together through direct updates', function (string $missingColumn) {
    $id = DB::table('reward_entitlements')->insertGetId(redeemedRewardAttributesForPersistence());

    expect(fn () => DB::table('reward_entitlements')->where('id', $id)->update([$missingColumn => null]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23514'));
})->with(['redeemed_at', 'redeemed_by_user_id']);

it('rejects a nonexistent redeeming actor through direct inserts', function () {
    $attributes = redeemedRewardAttributesForPersistence();

    expect(fn () => DB::table('reward_entitlements')->insert([...$attributes, 'redeemed_by_user_id' => 999999]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
});

it('rejects a nonexistent redeeming actor through direct updates', function () {
    $id = DB::table('reward_entitlements')->insertGetId(redeemedRewardAttributesForPersistence());

    expect(fn () => DB::table('reward_entitlements')->where('id', $id)->update(['redeemed_by_user_id' => 999999]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
});

it('restricts deletion of parents with Reward history', function (string $table, string $foreignKey) {
    $attributes = redeemedRewardAttributesForPersistence();
    DB::table('reward_entitlements')->insert($attributes);

    expect(fn () => DB::table($table)->where('id', $attributes[$foreignKey])->delete())
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
})->with([
    'pass' => ['customer_passes', 'customer_pass_id'],
    'Promotion' => ['promotions', 'promotion_id'],
    'Business' => ['businesses', 'business_id'],
    'redeeming owner' => ['users', 'redeemed_by_user_id'],
]);

it('rolls back only entitlements and reapplies without changing parent identities or Visits', function () {
    $attributes = redeemedRewardAttributesForPersistence();
    DB::table('reward_entitlements')->insert($attributes);
    $visitId = DB::table('visits')->insertGetId([
        'business_id' => $attributes['business_id'],
        'customer_pass_id' => $attributes['customer_pass_id'],
        'promotion_id' => $attributes['promotion_id'],
        'confirmed_by_user_id' => $attributes['redeemed_by_user_id'],
        'operation_id' => '019ca23f-7c00-7000-8000-000000000001',
        'awarded_points' => 8,
        'confirmed_at' => $attributes['unlocked_at'],
    ]);
    $migration = require database_path('migrations/2026_10_09_200001_create_reward_entitlements_table.php');

    $migration->down();
    $migration->up();

    $this->assertDatabaseHas('businesses', ['id' => $attributes['business_id']]);
    $this->assertDatabaseHas('customer_passes', ['id' => $attributes['customer_pass_id']]);
    $this->assertDatabaseHas('promotions', ['id' => $attributes['promotion_id']]);
    $this->assertDatabaseHas('users', ['id' => $attributes['redeemed_by_user_id']]);
    $this->assertDatabaseHas('visits', ['id' => $visitId, 'awarded_points' => 8]);
    $this->assertDatabaseCount('reward_entitlements', 0);
    DB::table('reward_entitlements')->insert($attributes);
    $this->assertDatabaseCount('reward_entitlements', 1);
});

/**
 * Builds unlocked synthetic facts without invoking unlock, redemption or Wallet operations.
 *
 * @return array{business_id: int, customer_pass_id: int, promotion_id: int, unlocked_at: string, redeemed_at: null, redeemed_by_user_id: null} Unredeemed entitlement attributes.
 */
function rewardAttributesForPersistence(): array
{
    $pass = CustomerPass::factory()->create();
    $promotion = $pass->business->promotions()->create([
        'local_start_date' => '2030-01-01',
        'local_end_date' => '2030-01-07',
        'target_points' => 8,
        'reward_title' => 'A coffee',
    ]);

    return [
        'business_id' => $pass->business_id,
        'customer_pass_id' => $pass->id,
        'promotion_id' => $promotion->id,
        'unlocked_at' => '2030-01-02 14:30:00.123456+00:00',
        'redeemed_at' => null,
        'redeemed_by_user_id' => null,
    ];
}

/**
 * Builds final redemption facts with explicit attribution to the owning User.
 *
 * @return array{business_id: int, customer_pass_id: int, promotion_id: int, unlocked_at: string, redeemed_at: string, redeemed_by_user_id: int} Redeemed entitlement attributes.
 */
function redeemedRewardAttributesForPersistence(): array
{
    $attributes = rewardAttributesForPersistence();

    return [
        ...$attributes,
        'redeemed_at' => '2030-01-03 14:15:00.654321+00:00',
        'redeemed_by_user_id' => Business::findOrFail($attributes['business_id'])->user_id,
    ];
}
