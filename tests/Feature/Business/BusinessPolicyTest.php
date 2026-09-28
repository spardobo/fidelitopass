<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows a verified owner to create only before a business exists', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(Gate::allows('create', Business::class))->toBeTrue();
    Business::factory()->for($user)->create();
    expect(Gate::allows('create', Business::class))->toBeFalse();
});

it('denies another owner and an unverified owner from updating', function () {
    $business = Business::factory()->create();
    $other = User::factory()->create();
    $unverified = User::factory()->unverified()->create();

    expect($other->can('update', $business))->toBeFalse()
        ->and($unverified->can('update', $business))->toBeFalse()
        ->and($unverified->can('create', Business::class))->toBeFalse()
        ->and($business->user->can('update', $business))->toBeTrue();
});

it('denies direct Livewire save by an unverified owner even with valid fields', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    Livewire::test('pages::business.profile')
        ->set('name', 'Negocio no verificado')
        ->set('timezone', 'UTC')
        ->call('save')
        ->assertForbidden();

    expect($user->business()->exists())->toBeFalse();
});

it('authorizes direct save against the current session and current ownership', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $form = Livewire::test('pages::business.profile')->set('name', 'Primer negocio')->set('timezone', 'UTC');
    Business::factory()->for($user)->create(['name' => 'Actual']);

    $form->call('save')->assertRedirect(route('dashboard'));
    expect($user->business()->firstOrFail()->name)->toBe('Primer negocio');
});
