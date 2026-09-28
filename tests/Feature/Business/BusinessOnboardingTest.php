<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates the sole business for the verified session owner', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::business.profile')
        ->set('name', 'Café Sur')
        ->set('timezone', 'America/Argentina/Buenos_Aires')
        ->call('save')
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseHas('businesses', [
        'user_id' => $user->id, 'name' => 'Café Sur', 'timezone' => 'America/Argentina/Buenos_Aires',
    ]);
    $this->assertDatabaseMissing('businesses', ['user_id' => $other->id]);
    expect(fn () => Livewire::test('pages::business.profile')->set('user_id', $other->id))
        ->toThrow(PublicPropertyNotFoundException::class);
});

it('edits the current owners existing business without creating another', function () {
    $mine = Business::factory()->create();
    $other = Business::factory()->create(['name' => 'Intocable', 'timezone' => 'UTC']);
    $this->actingAs($mine->user);

    Livewire::test('pages::business.profile')
        ->set('name', 'Nuevo nombre')
        ->set('timezone', 'Europe/Madrid')
        ->call('save')
        ->assertRedirect(route('dashboard'));

    expect($mine->fresh()->name)->toBe('Nuevo nombre')
        ->and($mine->fresh()->timezone)->toBe('Europe/Madrid')
        ->and($other->fresh()->name)->toBe('Intocable')
        ->and($other->fresh()->timezone)->toBe('UTC')
        ->and(Business::count())->toBe(2);
});

it('rejects invalid names and non IANA timezones without saving', function ($name, $timezone, $field) {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::business.profile')
        ->set('name', $name)
        ->set('timezone', $timezone)
        ->call('save')
        ->assertHasErrors([$field]);

    expect($user->business()->exists())->toBeFalse();
})->with([
    'missing name' => ['', 'UTC', 'name'],
    'long name' => [str_repeat('a', 256), 'UTC', 'name'],
    'invalid timezone' => ['Café Sur', 'Mars/Olympus', 'timezone'],
]);

it('does not let an unverified owner submit onboarding through Livewire', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    Livewire::test('pages::business.profile')
        ->set('name', 'Negocio')
        ->set('timezone', 'UTC')
        ->call('save')
        ->assertForbidden();

    expect($user->business()->exists())->toBeFalse();
});
