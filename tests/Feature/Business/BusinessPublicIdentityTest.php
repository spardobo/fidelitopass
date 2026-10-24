<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('assigns distinct UUIDv7 identities without accepting a mass-assigned public identifier', function () {
    $business = Business::factory()->create(['public_id' => (string) Str::uuid()]);
    $another = Business::factory()->create();

    expect(Str::isUuid($business->public_id, version: 7))->toBeTrue()
        ->and(Str::isUuid($another->public_id, version: 7))->toBeTrue()
        ->and($business->public_id)->not->toBe($another->public_id);
});

it('retains the public identity across profile appearance and Promotion changes', function () {
    $business = Business::factory()->create();
    $identity = $business->public_id;
    $business->update(['name' => 'Renamed', 'pass_background_color' => '#A77BFF']);
    $promotion = $business->promotions()->create([
        'local_start_date' => '2030-01-01', 'local_end_date' => '2030-01-03',
        'target_points' => 8, 'reward_title' => 'Coffee',
    ]);
    $promotion->delete();

    expect($business->fresh()->public_id)->toBe($identity);
});

it('rejects public identity reassignment through Eloquent', function () {
    $business = Business::factory()->create();

    expect(fn () => $business->forceFill(['public_id' => (string) Str::uuid7()])->save())
        ->toThrow(LogicException::class);
});

it('rejects public identity reassignment through direct SQL', function () {
    $business = Business::factory()->create();

    expect(fn () => DB::table('businesses')->where('id', $business->id)
        ->update(['public_id' => (string) Str::uuid7()]))->toThrow(QueryException::class);
});

it('enforces unique non-null UUIDv7 identities at the persistence boundary', function (string $identity) {
    $business = Business::factory()->create();
    $owner = User::factory()->create();

    expect(fn () => DB::table('businesses')->insert([
        'user_id' => $owner->id, 'name' => 'Bypass', 'timezone' => 'UTC',
        'public_id' => $identity === 'duplicate' ? $business->public_id : ($identity === 'null' ? null : $identity),
    ]))->toThrow(QueryException::class);
})->with(['duplicate', 'null', '01936380-0000-4000-8000-000000000001']);

it('accepts a UUIDv7 identity on direct creation and preserves it during profile updates', function () {
    $owner = User::factory()->create();
    $identity = (string) Str::uuid7();
    $businessId = DB::table('businesses')->insertGetId([
        'public_id' => $identity,
        'user_id' => $owner->id,
        'name' => 'Original',
        'timezone' => 'Europe/Madrid',
    ]);

    DB::table('businesses')->where('id', $businessId)->update([
        'public_id' => $identity,
        'name' => 'Renamed',
        'pass_background_color' => '#A77BFF',
    ]);

    $this->assertDatabaseHas('businesses', [
        'id' => $businessId,
        'public_id' => $identity,
        'user_id' => $owner->id,
        'name' => 'Renamed',
        'timezone' => 'Europe/Madrid',
        'pass_background_color' => '#A77BFF',
        'created_at' => null,
        'updated_at' => null,
    ]);
});
