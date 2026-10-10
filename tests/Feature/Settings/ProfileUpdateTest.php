<?php

namespace Tests\Feature\Settings;

use App\Models\Business;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Renders the persisted Business identity independently of account form state.
     */
    public function test_profile_shows_the_owned_business_identity(): void
    {
        $business = Business::factory()->create(['name' => 'Saved Business']);
        $this->actingAs($business->user);

        Livewire::test('pages::settings.profile')
            ->assertSee('Saved Business · Tu negocio');
    }

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors()->assertDispatched('toast-show', duration: 5000, slots: ['text' => __('Profile updated.')], dataset: ['variant' => 'success']);

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertSame('deleted', session('account.feedback'));
        $this->get('/')->assertOk();
        $this->assertFalse(session()->has('account.feedback'));

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password'])->assertDispatched('toast-show', slots: ['text' => __('account-feedback.validation')], dataset: ['variant' => 'danger']);

        $this->assertNotNull($user->fresh());
    }

    /** Preserves remembered logout and native deletion events without reinserting the account. */
    public function test_remembered_user_deletion_dispatches_native_events_and_remains_deleted(): void
    {
        $user = User::factory()->create();
        $this->assertNotEmpty($user->getRememberToken());
        auth()->guard('web')->login($user, remember: true);
        $events = [];
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        User::deleting(function (User $deleted) use (&$events): void {
            $events[] = ['deleting', $deleted->getKey()];
        });
        User::deleted(function (User $deleted) use (&$events): void {
            $events[] = ['deleted', $deleted->getKey()];
        });

        try {
            Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')
                ->assertHasNoErrors()->assertRedirect('/');
        } finally {
            User::setEventDispatcher($dispatcher);
        }

        $this->assertSame([['deleting', $user->id], ['deleted', $user->id]], $events);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /** Proves profile validation retains field feedback and stored account data. */
    public function test_invalid_profile_dispatches_validation_toast(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')->set('name', '')->call('updateProfileInformation')
            ->assertHasErrors(['name'])
            ->assertDispatched('toast-show', slots: ['text' => __('account-feedback.validation')], dataset: ['variant' => 'danger']);

        $this->assertSame($user->name, $user->fresh()->name);
    }

    /** Keeps account and authenticated session intact when profile persistence fails. */
    public function test_failed_profile_update_reports_safe_feedback(): void
    {
        $user = User::factory()->create();
        $original = $user->fresh()->getRawOriginal();
        $this->actingAs($user);
        Exceptions::fake();
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        User::updating(fn () => throw new \RuntimeException('Internal persistence failure.'));

        try {
            Livewire::test('pages::settings.profile')->set('name', 'Unsaved Owner')->call('updateProfileInformation')
                ->assertDispatched('toast-show', slots: ['text' => __('account-feedback.unexpected')], dataset: ['variant' => 'danger'])
                ->assertDontSee('Internal persistence failure.');
        } finally {
            User::setEventDispatcher($dispatcher);
        }

        Exceptions::assertReported(\RuntimeException::class);
        $this->assertSame($original, $user->fresh()->getRawOriginal());
    }

    /** Proves resend success uses the toast channel rather than duplicate inline status. */
    public function test_verification_resend_dispatches_success_toast(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        Notification::fake();

        Livewire::test('pages::settings.profile')->call('resendVerificationNotification')
            ->assertDispatched('toast-show', duration: 5000, slots: ['text' => __('A new verification link has been sent to your email address.')], dataset: ['variant' => 'success']);

        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertFalse(session()->has('status'));
    }

    /** Reports a delivery failure without exposing notification internals. */
    public function test_verification_delivery_failure_dispatches_safe_toast(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        Exceptions::fake();
        $this->mock(Dispatcher::class)
            ->shouldReceive('send')->once()->andThrow(new \RuntimeException('Internal notification failure.'));

        Livewire::test('pages::settings.profile')->call('resendVerificationNotification')
            ->assertDispatched('toast-show', slots: ['text' => __('account-feedback.unexpected')], dataset: ['variant' => 'danger'])
            ->assertDontSee('Internal notification failure.');

        Exceptions::assertReported(\RuntimeException::class);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    /** A failed delete must not log out the owner before safe feedback can render. */
    public function test_failed_deletion_preserves_authentication_and_reports_safe_toast(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Exceptions::fake();
        $component = Livewire::test('pages::settings.delete-user-modal')->set('password', 'password');
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        User::deleting(fn () => throw new \RuntimeException('Internal deletion failure.'));

        try {
            $response = $component->call('deleteUser');
            $this->assertAuthenticatedAs($user);
            $response->assertNoRedirect()
                ->assertDispatched('toast-show', slots: ['text' => __('account-feedback.unexpected')], dataset: ['variant' => 'danger'])
                ->assertDontSee('Internal deletion failure.');
        } finally {
            User::setEventDispatcher($dispatcher);
        }

        Exceptions::assertReported(\RuntimeException::class);
        $this->assertModelExists($user);
        $this->assertFalse(session()->has('account.feedback'));
    }

    /** Unknown markers are consumed without rendering arbitrary session content. */
    public function test_public_account_feedback_accepts_only_the_deleted_marker(): void
    {
        $this->withSession(['account.feedback' => '<script>arbitrary-marker</script>'])->get('/')
            ->assertOk()->assertDontSee('arbitrary-marker');

        $this->assertFalse(session()->has('account.feedback'));
    }
}
