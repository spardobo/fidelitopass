<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('Spanish is the initial interface locale', function (): void {
    expect(config('app.locale'))->toBe('es');
    expect(config('app.fallback_locale'))->toBe('es');

    $this->get('/login')
        ->assertSee('lang="es"', false)
        ->assertSeeText('Iniciar sesión')
        ->assertSeeText('Contraseña')
        ->assertDontSeeText('Log in to your account');
});

test('guest pages render Spanish copy', function (string $path, string $label): void {
    $this->get($path)->assertSeeText($label);
})->with([
    'welcome' => ['/', 'Haz que volver sea parte del juego.'],
    'registration' => ['/register', 'Crear una cuenta'],
    'forgot password' => ['/forgot-password', 'Recuperar contraseña'],
    'password reset' => ['/reset-password/test-token', 'Restablecer contraseña'],
]);

test('landing page translates its title, description and accessible navigation', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Haz que volver sea parte del juego - '.config('app.name'))
        ->assertSee('Crea tu cuenta y registra tu negocio')
        ->assertSee('aria-label="Secciones de la página"', false)
        ->assertSee('Vista conceptual de una tarjeta con seis de diez puntos');
});

test('project pages resolve grouped translations without leaking keys', function (): void {
    expect(__('landing.page_title'))->toBe('Haz que volver sea parte del juego');
    expect(__('landing.page_description'))->toStartWith('Crea tu cuenta y registra tu negocio');
    expect(__('landing.navigation.page_sections'))->toBe('Secciones de la página');
    expect(__('business.profile_title'))->toBe('Perfil del negocio');
    expect(__('business.dashboard.page_title'))->toBe('Panel del negocio');

    $this->get(route('home'))
        ->assertSee('aria-label="Secciones de la página"', false)
        ->assertDontSee('landing.page_title')
        ->assertDontSee('landing.page_description')
        ->assertDontSee('landing.navigation.page_sections');

    $user = User::factory()->create();
    $this->actingAs($user);
    $this->get(route('business.create'))
        ->assertSee('Perfil del negocio - '.config('app.name'))
        ->assertSeeText('Configura tu negocio')
        ->assertDontSee('business.profile_title')
        ->assertDontSee('business.onboarding.heading');

    expect(__('Log in to your account'))->toBe('Iniciar sesión en tu cuenta');
});

test('authenticated settings translate Livewire titles and navigation', function (): void {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('profile.edit'))
        ->assertSeeText('Perfil')
        ->assertSeeText('Configuración')
        ->assertSeeText('Cerrar sesión');

    expect($response->getContent())->toMatch('/<title>\s*Configuración del perfil -/u');
});

test('starter validation uses Spanish field names and messages', function (): void {
    $errors = Validator::make(['email' => 'invalid', 'password' => 'short'], [
        'name' => ['required'], 'email' => ['email'], 'password' => ['min:8'],
    ])->errors();

    expect($errors->first('name'))->toBe('El campo nombre es obligatorio.');
    expect($errors->first('email'))->toBe('El campo correo electrónico no es un correo válido.');
    expect($errors->first('password'))->toBe('El campo contraseña debe contener al menos 8 caracteres.');
    expect(__('auth.failed'))->toBe('Estas credenciales no coinciden con nuestros registros.');
    expect(__('passwords.sent'))->toBe('Le hemos enviado por correo electrónico el enlace para restablecer su contraseña.');
});

test('standard numeric validation is translated', function (): void {
    $errors = Validator::make(['name' => 5], ['name' => ['numeric', 'between:10,20']])->errors();

    expect($errors->first('name'))->toBe('El campo nombre tiene que estar entre 10 y 20.');
});

test('authentication rejection and two factor validation show Spanish messages', function (): void {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'Estas credenciales no coinciden con nuestros registros.']);

    $this->actingAs($user);
    Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
        ->set('showVerificationStep', true)
        ->set('code', '12')
        ->call('confirmTwoFactor')
        ->assertSeeText('El campo código debe contener 6 caracteres.');
});

test('unreadable recovery codes render a Spanish error without exposing recovery data', function (): void {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => 'malformed-recovery-payload',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::settings.two-factor.recovery-codes')
        ->assertHasErrors(['recoveryCodes'])
        ->assertSet('recoveryCodes', [])
        ->assertSeeText('No se pudieron cargar los códigos de recuperación.')
        ->assertDontSeeText('Failed to load recovery codes')
        ->assertDontSeeText('malformed-recovery-payload');
});

test('password reset and verification mail render Spanish actions and copy', function (): void {
    $user = User::factory()->make(['id' => 1]);
    $reset = (new ResetPassword('test-token'))->toMail($user);
    $verification = (new VerifyEmail)->toMail($user);

    expect($reset->subject)->toBe('Restablezca su contraseña');
    expect($reset->actionText)->toBe('Restablecer contraseña');
    expect($verification->subject)->toBe('Verifique su dirección de correo electrónico');
    expect($verification->actionText)->toBe('Confirme su correo electrónico');
    expect((string) $reset->render())->toContain(
        '¡Hola!', 'Saludos', 'Todos los derechos reservados.',
        'Ha recibido este mensaje porque se solicitó un restablecimiento de contraseña para su cuenta.',
        'Si está teniendo problemas al hacer clic en el botón',
    );
    expect((string) $verification->render())->toContain('Por favor, haga clic en el botón de abajo para verificar su dirección de correo electrónico.');
});
