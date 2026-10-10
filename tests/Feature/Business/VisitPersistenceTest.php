<?php

use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('persists accepted Visit facts with their owner and immutable precise confirmation instant', function () {
    $attributes = visitAttributesForPersistence();
    $visit = new Visit;
    $visit->forceFill($attributes)->save();

    $persisted = $visit->fresh();

    $this->assertModelExists($persisted);
    expect($persisted->business->id)->toBe($attributes['business_id']);
    expect($persisted->customerPass->id)->toBe($attributes['customer_pass_id']);
    expect($persisted->promotion->id)->toBe($attributes['promotion_id']);
    expect($persisted->confirmedBy->id)->toBe($attributes['confirmed_by_user_id']);
    expect($persisted->awarded_points)->toBe(3);
    expect($persisted->operation_id)->toBe($attributes['operation_id']);
    expect($persisted->confirmed_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($persisted->confirmed_at->utc()->format('Y-m-d H:i:s.u'))->toBe('2030-01-02 14:30:00.123456');
});

it('preserves a supplied domain instant when the model receives a timezone offset', function () {
    $visit = new Visit;
    $visit->forceFill([
        ...visitAttributesForPersistence(),
        'confirmed_at' => CarbonImmutable::parse('2030-01-02 10:30:00.123456-04:00'),
    ])->save();

    expect($visit->fresh()->confirmed_at->utc()->format('Y-m-d H:i:s.u'))->toBe('2030-01-02 14:30:00.123456');
});

it('allows legitimate repeat Visits on the same day while preserving awarded outcomes', function () {
    $attributes = visitAttributesForPersistence();
    DB::table('visits')->insert($attributes);
    DB::table('visits')->insert([...$attributes, 'operation_id' => (string) Str::uuid(), 'awarded_points' => 1]);

    DB::table('promotions')->where('id', $attributes['promotion_id'])->update(['target_points' => 20]);

    $this->assertDatabaseCount('visits', 2);
    expect(Visit::query()->orderBy('id')->pluck('awarded_points')->all())->toBe([3, 1]);
});

it('rejects duplicate operation identities through direct inserts across Businesses', function () {
    $first = visitAttributesForPersistence();
    DB::table('visits')->insert($first);
    $second = visitAttributesForPersistence();

    expect(fn () => DB::table('visits')->insert([...$second, 'operation_id' => $first['operation_id']]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23505'));
});

it('rejects updates that duplicate an accepted operation identity', function () {
    $first = visitAttributesForPersistence();
    DB::table('visits')->insert($first);
    $secondId = DB::table('visits')->insertGetId(visitAttributesForPersistence());

    expect(fn () => DB::table('visits')->where('id', $secondId)->update(['operation_id' => $first['operation_id']]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23505'));
});

it('rejects missing Visit parents through direct inserts', function (string $column, ?int $value, string $sqlState) {
    $attributes = visitAttributesForPersistence();

    expect(fn () => DB::table('visits')->insert([...$attributes, $column => $value]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe($sqlState));
})->with(['business_id', 'customer_pass_id', 'promotion_id', 'confirmed_by_user_id'])
    ->with(['null' => [null, '23502'], 'nonexistent' => [999999, '23503']]);

it('rejects removing required accepted facts through direct updates', function (string $column) {
    $id = DB::table('visits')->insertGetId(visitAttributesForPersistence());

    expect(fn () => DB::table('visits')->where('id', $id)->update([$column => null]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23502'));
})->with(['operation_id', 'confirmed_at', 'awarded_points', 'confirmed_by_user_id']);

it('rejects cross-Business Visit parents through direct inserts', function (string $column) {
    $attributes = visitAttributesForPersistence();
    $foreignAttributes = visitAttributesForPersistence();

    expect(fn () => DB::table('visits')->insert([...$attributes, $column => $foreignAttributes[$column]]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
})->with(['business_id', 'customer_pass_id', 'promotion_id']);

it('rejects cross-Business changes through direct Visit updates', function (string $column) {
    $id = DB::table('visits')->insertGetId(visitAttributesForPersistence());
    $foreignAttributes = visitAttributesForPersistence();

    expect(fn () => DB::table('visits')->where('id', $id)->update([$column => $foreignAttributes[$column]]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
})->with(['business_id', 'customer_pass_id', 'promotion_id']);

it('rejects moving referenced Visit parents to another Business', function (string $table, string $foreignKey) {
    $attributes = visitAttributesForPersistence();
    DB::table('visits')->insert($attributes);
    $otherBusiness = Business::factory()->create();

    expect(fn () => DB::table($table)->where('id', $attributes[$foreignKey])->update(['business_id' => $otherBusiness->id]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
})->with(['pass' => ['customer_passes', 'customer_pass_id'], 'Promotion' => ['promotions', 'promotion_id']]);

it('restricts deletion of parents with accepted Visit history', function (string $table, string $foreignKey) {
    $attributes = visitAttributesForPersistence();
    DB::table('visits')->insert($attributes);

    expect(fn () => DB::table($table)->where('id', $attributes[$foreignKey])->delete())
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
})->with([
    'pass' => ['customer_passes', 'customer_pass_id'],
    'Promotion' => ['promotions', 'promotion_id'],
    'Business' => ['businesses', 'business_id'],
    'confirming owner' => ['users', 'confirmed_by_user_id'],
]);

it('rejects nonpositive awarded points through direct inserts', function (int $points) {
    $attributes = visitAttributesForPersistence();

    expect(fn () => DB::table('visits')->insert([...$attributes, 'awarded_points' => $points]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23514'));
})->with(['zero' => 0, 'negative' => -1]);

it('rejects nonpositive awarded points through direct updates', function (int $points) {
    $id = DB::table('visits')->insertGetId(visitAttributesForPersistence());

    expect(fn () => DB::table('visits')->where('id', $id)->update(['awarded_points' => $points]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23514'));
})->with(['zero' => 0, 'negative' => -1]);

it('rolls back only Visit facts and reapplies without changing parent identities', function () {
    $attributes = visitAttributesForPersistence();
    DB::table('visits')->insert($attributes);
    $migration = require database_path('migrations/2026_10_09_200000_create_visits_table.php');

    $migration->down();
    $migration->up();

    $this->assertDatabaseHas('customer_passes', ['id' => $attributes['customer_pass_id']]);
    $this->assertDatabaseHas('promotions', ['id' => $attributes['promotion_id']]);
    $this->assertDatabaseHas('users', ['id' => $attributes['confirmed_by_user_id']]);
    $this->assertDatabaseCount('visits', 0);
    DB::table('visits')->insert($attributes);
    $this->assertDatabaseCount('visits', 1);
});

/**
 * Builds accepted synthetic Visit facts without invoking registration or Wallet operations.
 *
 * @return array{business_id: int, customer_pass_id: int, promotion_id: int, confirmed_by_user_id: int, operation_id: string, awarded_points: int, confirmed_at: string} Complete persistence attributes.
 */
function visitAttributesForPersistence(): array
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
        'confirmed_by_user_id' => $pass->business->user_id,
        'operation_id' => (string) Str::uuid(),
        'awarded_points' => 3,
        'confirmed_at' => '2030-01-02 14:30:00.123456+00:00',
    ];
}
