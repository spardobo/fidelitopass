<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_mismatched_password_confirmation_returns_feedback_without_creating_a_user(): void
    {
        Notification::fake();
        $message = __('validation.confirmed', ['attribute' => __('validation.attributes.password')]);

        $response = $this->from(route('register'))->post(route('register.store'), [
            'name' => 'New Owner',
            'email' => 'owner@example.test',
            'password' => 'password',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertRedirect(route('register'))
            ->assertSessionHasErrors(['password' => $message])
            ->assertSessionHasInput('name', 'New Owner')
            ->assertSessionHasInput('email', 'owner@example.test');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
        Notification::assertNothingSent();

        $session = app('session.store');
        $this->withCookie($session->getName(), $session->getId())
            ->get(route('register'))->assertSee($message);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }
}
