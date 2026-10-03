<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const RETIRED_TWO_FACTOR_COLUMNS = [
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    public function test_users_table_cannot_store_two_factor_credentials(): void
    {
        foreach (self::RETIRED_TWO_FACTOR_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), "Unexpected users column: {$column}");
        }
    }

    public function test_retirement_migration_is_safe_when_columns_are_already_absent(): void
    {
        $migration = require database_path('migrations/2026_09_25_024647_remove_two_factor_columns_from_users_table.php');

        $migration->up();
        $migration->down();

        foreach (self::RETIRED_TWO_FACTOR_COLUMNS as $column) {
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

        foreach (self::RETIRED_TWO_FACTOR_COLUMNS as $column) {
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

    public function test_business_owner_login_keeps_the_intended_destination(): void
    {
        $business = Business::factory()->create();
        $user = $business->user;
        $destination = route('profile.edit');

        $this->withSession(['url.intended' => $destination])->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasNoErrors()->assertRedirect($destination);

        $this->get($destination)->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertModelExists($business);
    }

    public function test_business_owner_default_login_reaches_dashboard(): void
    {
        $business = Business::factory()->create();
        $user = $business->user;

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->get(route('dashboard'))->assertOk();
        $this->assertModelExists($business);
    }

    public function test_business_owner_verification_preserves_the_intended_dashboard(): void
    {
        $user = User::factory()->unverified()->create();
        $business = Business::factory()->for($user)->create();
        $destination = route('dashboard');
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->get($destination)->assertRedirect(route('verification.notice'));
        $this->get($verificationUrl)->assertRedirect($destination);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get($destination)->assertOk();
        $this->assertModelExists($business);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => __('auth.failed')])
            ->assertSessionHasInput('email', $user->email);

        // HTTP tests do not automatically carry the POST session cookie into the redirected GET.
        $session = app('session.store');
        $this->withCookie($session->getName(), $session->getId())
            ->get(route('login'))->assertSee(__('auth.failed'));

        $this->assertGuest();
    }

    public function test_remembered_login_issues_a_persistent_cookie(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_without_remember_does_not_issue_a_persistent_cookie(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertCookieMissing(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
    }

    public function test_disabled_two_factor_passkey_and_discovery_routes_are_unavailable(): void
    {
        foreach ([
            'two-factor.login',
            'two-factor.enable',
            'passkey.login-options',
            'passkey.login',
            'passkey.confirm-options',
            'passkey.confirm',
            'passkey.registration-options',
            'passkey.store',
            'passkey.destroy',
            'well-known.passkeys',
        ] as $name) {
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
