<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('persists a business owned by its user with its own IANA timezone', function () {
    $owner = User::factory()->create();

    $business = $owner->business()->create([
        'name' => 'Café del Centro',
        'timezone' => 'America/La_Paz',
    ]);

    expect($owner->fresh()->business->is($business))->toBeTrue();
    expect($business->fresh()->user->is($owner))->toBeTrue();
    $this->assertDatabaseHas('businesses', [
        'id' => $business->id,
        'user_id' => $owner->id,
        'name' => 'Café del Centro',
        'timezone' => 'America/La_Paz',
    ]);
});

it('creates a business with its own owner when using the factory', function () {
    $business = Business::factory()->create();

    expect($business->user)->toBeInstanceOf(User::class);
    expect($business->timezone)->toBe('Europe/Madrid');
    $this->assertModelExists($business);
});

it('rejects a second business for the same owner through direct database writes', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    expect(fn () => DB::table('businesses')->insert([
        'user_id' => $owner->id,
        'name' => 'Duplicate',
        'timezone' => 'Europe/Madrid',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);

});

it('does not allow mass assignment to change the owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $business = Business::factory()->for($owner)->create();

    $business->fill(['user_id' => $other->id, 'name' => 'Updated'])->save();

    expect($business->fresh()->user->is($owner))->toBeTrue();
    expect($business->fresh()->name)->toBe('Updated');
});
