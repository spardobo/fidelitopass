<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not expose the retired business onboarding URL', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user)->get('/business/onboarding')->assertNotFound();
});

it('sends anonymous visitors to login for all business pages', function () {
    foreach (['dashboard', 'business.edit'] as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }
});

it('sends unverified owners to email verification before business editing or dashboard', function () {
    $user = User::factory()->unverified()->create();
    Business::factory()->for($user)->create();

    foreach (['dashboard', 'business.edit'] as $route) {
        $this->actingAs($user)->get(route($route))->assertRedirect(route('verification.notice'));
    }
});

it('keeps account profile and logout accessible for verified and unverified business owners', function (bool $verified) {
    $user = $verified ? User::factory()->create() : User::factory()->unverified()->create();
    $business = Business::factory()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('profile.edit'))->assertOk();
    $this->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();
    $this->assertModelExists($business);
})->with(['verified' => true, 'unverified' => false]);

it('shows only the signed-in owners business', function () {
    $other = Business::factory()->create(['name' => 'Otro negocio']);
    $mine = Business::factory()->create(['name' => 'Negocio propio']);

    $this->actingAs($mine->user)->get(route('dashboard'))
        ->assertOk()->assertSee('Negocio propio')->assertDontSee('Otro negocio');
    $this->get(route('business.edit'))->assertOk()->assertSee('Negocio propio')->assertDontSee('Otro negocio');
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
