<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_password_confirms_the_session_and_redirects_to_dashboard(): void
    {
        $user = User::factory()->create();
        $confirmedAt = $this->freezeTime();

        $response = $this->actingAs($user)
            ->post(route('password.confirm.store'), ['password' => 'password']);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas('auth.password_confirmed_at', $confirmedAt->timestamp);
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_returns_feedback_without_confirming_the_session(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->from(route('password.confirm'))
            ->post(route('password.confirm.store'), ['password' => 'wrong-password']);

        $response->assertRedirect(route('password.confirm'))
            ->assertSessionHasErrors(['password' => __('The provided password was incorrect.')])
            ->assertSessionMissing('auth.password_confirmed_at');
        $this->assertAuthenticatedAs($user);

        $session = app('session.store');
        $this->withCookie($session->getName(), $session->getId())
            ->get(route('password.confirm'))->assertSee(__('The provided password was incorrect.'));
    }

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('password.confirm'));

        $response->assertOk()
            ->assertSee('data-test="confirm-password-button"', false)
            ->assertDontSee('passkey.confirm-options');
    }
}
