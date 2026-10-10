<?php

namespace Tests\Feature\Settings;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Renders the persisted Business identity independently of account form state.
     */
    public function test_security_shows_the_owned_business_identity(): void
    {
        $business = Business::factory()->create(['name' => 'Saved Business']);
        $this->actingAs($business->user);

        Livewire::test('pages::settings.security')
            ->assertSee('Saved Business · Tu negocio');
    }

    public function test_security_settings_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            /* @chisel-password-confirmation */
            ->withSession(['auth.password_confirmed_at' => time()])
            /* @end-chisel-password-confirmation */
            ->get(route('security.edit'));

        $response->assertOk();

        $response->assertSee('Actualizar contraseña');
        $response->assertDontSee('Claves de acceso');
        $response->assertDontSee('delete-passkey-modal');
        $response->assertDontSee('Autenticación de doble factor');
    }

    /* @chisel-password-confirmation */
    public function test_security_settings_page_requires_password_confirmation_when_enabled(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('security.edit'));

        $response->assertRedirect(route('password.confirm'));
    }
    /* @end-chisel-password-confirmation */

    public function test_security_settings_page_renders_without_two_factor_when_feature_is_disabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            /* @chisel-password-confirmation */
            ->withSession(['auth.password_confirmed_at' => time()])
            /* @end-chisel-password-confirmation */
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('Actualizar contraseña')
            ->assertDontSee('Administra tus claves de acceso para iniciar sesión sin contraseña')
            ->assertDontSee('Añade una clave de acceso para iniciar sesión sin contraseña')
            ->assertDontSee('Autenticación de doble factor');
    }

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword');

        $response->assertHasNoErrors()->assertDispatched('toast-show', duration: 5000, slots: ['text' => __('Password updated.')], dataset: ['variant' => 'success']);

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.security')
            ->set('current_password', 'wrong-password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword');

        $response->assertHasErrors(['current_password'])
            ->assertSet('current_password', '')->assertSet('password', '')->assertSet('password_confirmation', '')
            ->assertDispatched('toast-show', slots: ['text' => __('account-feedback.validation')], dataset: ['variant' => 'danger']);
    }

    /** Reports failed password persistence and clears transient password fields. */
    public function test_failed_password_update_reports_safe_feedback_without_changing_password(): void
    {
        $user = User::factory()->create();
        $original = $user->fresh()->getRawOriginal();
        $this->actingAs($user);
        Exceptions::fake();
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        User::updating(fn () => throw new \RuntimeException('Internal password failure.'));

        try {
            Livewire::test('pages::settings.security')->set('current_password', 'password')
                ->set('password', 'replacement-password')->set('password_confirmation', 'replacement-password')->call('updatePassword')
                ->assertSet('current_password', '')->assertSet('password', '')->assertSet('password_confirmation', '')
                ->assertDispatched('toast-show', slots: ['text' => __('account-feedback.unexpected')], dataset: ['variant' => 'danger'])
                ->assertDontSee('Internal password failure.');
        } finally {
            User::setEventDispatcher($dispatcher);
        }

        Exceptions::assertReported(\RuntimeException::class);
        $this->assertSame($original, $user->fresh()->getRawOriginal());
    }
}
