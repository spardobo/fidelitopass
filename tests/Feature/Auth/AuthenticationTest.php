<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()->assertDontSee('passkey.login-options');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrorsIn('email');

        $this->assertGuest();
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_stored_two_factor_credentials_do_not_challenge_password_login_when_feature_is_disabled(): void
    {
        $this->assertFalse(Features::enabled(Features::twoFactorAuthentication()));

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->two_factor_secret);
    }

    public function test_disabled_two_factor_passkey_and_discovery_routes_are_unavailable(): void
    {
        foreach (['two-factor.login', 'two-factor.enable', 'passkey.login-options', 'passkey.login', 'passkey.confirm-options', 'passkey.confirm', 'passkey.registration-options', 'passkey.store', 'passkey.destroy', 'well-known.passkeys'] as $name) {
            $this->assertFalse(Route::has($name), "Unexpected route: {$name}");
        }

        $this->get('/two-factor-challenge')->assertNotFound();
        $this->get('/passkeys/login/options')->assertNotFound();
        $this->get('/.well-known/passkey-endpoints')->assertNotFound();
    }

    public function test_registration_verification_and_password_reset_routes_remain_available(): void
    {
        foreach (['register', 'verification.notice', 'password.request', 'password.reset'] as $name) {
            $this->assertTrue(Route::has($name), "Missing route: {$name}");
        }
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }
}
