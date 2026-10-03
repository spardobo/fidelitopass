<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('denies another owner and an unverified owner from updating', function () {
    $business = Business::factory()->create();
    $otherBusiness = Business::factory()->create();
    $unverified = User::factory()->unverified()->create();
    $unverifiedBusiness = Business::factory()->for($unverified)->create();

    expect($otherBusiness->user->can('update', $business))->toBeFalse()
        ->and($unverified->can('update', $unverifiedBusiness))->toBeFalse()
        ->and($business->user->can('update', $business))->toBeTrue();
});

it('denies direct Livewire save after the owners verification is revoked', function () {
    $business = Business::factory()->create(['name' => 'Original', 'timezone' => 'UTC']);
    $user = $business->user;
    $this->actingAs($user);
    $form = Livewire::test('pages::business.profile');
    $user->forceFill(['email_verified_at' => null])->save();

    $form->set('name', 'Negocio no verificado')
        ->set('timezone', 'Europe/Madrid')
        ->call('save')
        ->assertForbidden();

    expect($business->fresh()->name)->toBe('Original')
        ->and($business->fresh()->timezone)->toBe('UTC');
});

it('authorizes direct save against the current session and current ownership', function () {
    $business = Business::factory()->create(['name' => 'Original', 'timezone' => 'UTC']);
    $otherBusiness = Business::factory()->create(['name' => 'Otro negocio', 'timezone' => 'UTC']);
    $this->actingAs($business->user);
    $form = Livewire::test('pages::business.profile')
        ->set('name', 'Nombre actualizado')
        ->set('timezone', 'Europe/Madrid');

    $this->actingAs($otherBusiness->user);
    $form->call('save')->assertRedirect(route('dashboard'));

    expect($business->fresh()->name)->toBe('Original')
        ->and($business->fresh()->timezone)->toBe('UTC');
    expect($otherBusiness->fresh()->name)->toBe('Nombre actualizado')
        ->and($otherBusiness->fresh()->timezone)->toBe('Europe/Madrid');
    expect(Business::count())->toBe(2);
});
