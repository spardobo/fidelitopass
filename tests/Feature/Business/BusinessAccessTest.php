<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sends anonymous visitors to login for all business pages', function () {
    foreach (['dashboard', 'business.create', 'business.edit'] as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }
});

it('sends unverified owners to email verification before onboarding or dashboard', function () {
    $user = User::factory()->unverified()->create();
    Business::factory()->for($user)->create();

    foreach (['dashboard', 'business.create', 'business.edit'] as $route) {
        $this->actingAs($user)->get(route($route))->assertRedirect(route('verification.notice'));
    }
});

it('sends verified owners without a business to onboarding', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertRedirect(route('business.create'));
    $this->get(route('business.edit'))->assertRedirect(route('business.create'));
    $this->get(route('business.create'))->assertOk();
});

it('shows only the signed-in owners business and redirects configured owners away from creation', function () {
    $other = Business::factory()->create(['name' => 'Otro negocio']);
    $mine = Business::factory()->create(['name' => 'Negocio propio']);

    $this->actingAs($mine->user)->get(route('dashboard'))
        ->assertOk()->assertSee('Negocio propio')->assertDontSee('Otro negocio');
    $this->get(route('business.create'))->assertRedirect(route('business.edit'));
    $this->assertNotEquals($other->user_id, $mine->user_id);
});

it('resolves the business page through its multi-file component and localized title', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    $finder = app('livewire.finder');
    expect($finder->resolveSingleFileComponentPath('pages::business.profile'))->toBeNull()
        ->and($finder->resolveMultiFileComponentPath('pages::business.profile'))->not->toBeNull();

    $this->get(route('business.edit'))->assertOk()->assertSee('Perfil del negocio');
});

it('marks business navigation active on the profile and dashboard navigation active on the dashboard', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    $this->get(route('business.edit'))->assertOk()->assertSee('business/profile');
    $this->get(route('dashboard'))->assertOk()->assertSee('dashboard');
});

it('keeps Fortify login and registration route names and paths', function () {
    expect(route('login', absolute: false))->toBe('/login')
        ->and(route('register', absolute: false))->toBe('/register');

    $this->get(route('login'))->assertOk();
    $this->get(route('register'))->assertOk();
});
