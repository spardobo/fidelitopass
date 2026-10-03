<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email]);

        $response->assertRedirect(route('password.request'))
            ->assertSessionHas('status', __('passwords.sent'));
        Notification::assertSentTo($user, ResetPassword::class);
        $this->get(route('password.request'))->assertSee(__('passwords.sent'));
    }

    public function test_unknown_email_returns_reset_feedback_without_sending_a_notification(): void
    {
        Notification::fake();

        $response = $this->from(route('password.request'))->post(route('password.email'), [
            'email' => 'missing@example.test',
        ]);

        $response->assertRedirect(route('password.request'))
            ->assertSessionHasErrors(['email' => __('passwords.user')]);
        Notification::assertNothingSent();

        $session = app('session.store');
        $this->withCookie($session->getName(), $session->getId())
            ->get(route('password.request'))->assertSee(__('passwords.user'));
    }

    public function test_invalid_reset_token_preserves_the_password_and_returns_feedback(): void
    {
        $user = User::factory()->create();
        $originalPassword = $user->password;
        $resetUrl = route('password.reset', ['token' => 'invalid-token', 'email' => $user->email]);

        $response = $this->from($resetUrl)->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertRedirect($resetUrl)
            ->assertSessionHasErrors(['email' => __('passwords.token')])
            ->assertSessionHasInput('email', $user->email);
        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertGuest();

        $session = app('session.store');
        $this->withCookie($session->getName(), $session->getId())
            ->get($resetUrl)->assertSee(__('passwords.token'));
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login', absolute: false))
                ->assertSessionHas('status', __('passwords.reset'));
            $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
            $this->assertGuest();
            $this->get(route('login'))->assertSee(__('passwords.reset'));

            return true;
        });
    }
}
