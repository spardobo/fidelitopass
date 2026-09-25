<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_cannot_store_two_factor_credentials(): void
    {
        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), "Unexpected users column: {$column}");
        }
    }

    public function test_retirement_migration_is_safe_when_columns_are_already_absent(): void
    {
        $migration = require database_path('migrations/2026_09_25_024647_remove_two_factor_columns_from_users_table.php');

        $migration->up();
        $migration->down();

        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), "Unexpected users column after rollback: {$column}");
        }
    }

    public function test_retirement_migration_drops_only_existing_two_factor_columns(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_recovery_codes')->nullable();
        });

        $migration = require database_path('migrations/2026_09_25_024647_remove_two_factor_columns_from_users_table.php');
        $migration->up();
        $migration->down();

        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), "Unexpected users column after rollback: {$column}");
        }
    }

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
