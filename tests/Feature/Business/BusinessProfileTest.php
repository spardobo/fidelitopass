<?php

use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('keeps the profile editing heading when an owner clears the name', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    Livewire::test('pages::business.profile')
        ->set('name', '')
        ->assertSee('Perfil del negocio')
        ->assertDontSee('Configura tu negocio')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($business->fresh()->name)->toBe($business->name);
});

it('edits the current owners existing business without creating another', function () {
    $ownedBusiness = Business::factory()->create();
    $foreignBusiness = Business::factory()->create(['name' => 'Intocable', 'timezone' => 'UTC']);
    $this->actingAs($ownedBusiness->user);

    Livewire::test('pages::business.profile')
        ->assertSet('name', $ownedBusiness->name)
        ->assertSet('timezone', $ownedBusiness->timezone)
        ->set('name', 'Nuevo nombre')
        ->set('timezone', 'Europe/Madrid')
        ->call('save')
        ->assertRedirect(route('dashboard'));

    expect($ownedBusiness->fresh()->name)->toBe('Nuevo nombre')
        ->and($ownedBusiness->fresh()->timezone)->toBe('Europe/Madrid');

    expect($foreignBusiness->fresh()->name)->toBe('Intocable')
        ->and($foreignBusiness->fresh()->timezone)->toBe('UTC');

    expect(Business::count())->toBe(2);
});

it('does not expose owner or business identifiers as writable profile state', function (string $property) {
    $business = Business::factory()->create();
    $foreignBusiness = Business::factory()->create();
    $this->actingAs($business->user);
    $value = $property === 'user_id' ? $foreignBusiness->user_id : $foreignBusiness->id;

    expect(fn () => Livewire::test('pages::business.profile')->set($property, $value))
        ->toThrow(PublicPropertyNotFoundException::class);
})->with(['user_id', 'business_id']);

it('rejects invalid names and non IANA timezones without changing the profile', function ($name, $timezone, $field) {
    $business = Business::factory()->create(['name' => 'Café Sur', 'timezone' => 'UTC']);
    $this->actingAs($business->user);

    Livewire::test('pages::business.profile')
        ->set('name', $name)
        ->set('timezone', $timezone)
        ->call('save')
        ->assertHasErrors([$field]);

    expect($business->fresh()->name)->toBe('Café Sur')
        ->and($business->fresh()->timezone)->toBe('UTC');
    expect(Business::count())->toBe(1);
})->with([
    'missing name' => ['', 'UTC', 'name'],
    'long name' => [str_repeat('a', 256), 'UTC', 'name'],
    'invalid timezone' => ['Café Sur', 'Mars/Olympus', 'timezone'],
    'explicit timezone required' => ['Café Sur', '', 'timezone'],
]);
