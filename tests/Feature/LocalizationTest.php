<?php

use App\Actions\Promotions\SavePromotionDraft;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
    'welcome' => ['/', 'Dale a tus clientes una razón para volver.'],
    'registration' => ['/register', 'Crear una cuenta'],
    'forgot password' => ['/forgot-password', 'Recuperar contraseña'],
    'password reset' => ['/reset-password/test-token', 'Restablecer contraseña'],
]);

test('landing page translates its title, description and accessible navigation', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('FidelitoPass - Dale a tus clientes una razón para volver.')
        ->assertSee('Crea Promociones de puntos y ofrece a tus clientes un Pase para Google Wallet con su progreso y recompensas.')
        ->assertSee('aria-label="Secciones de la página"', false)
        ->assertSee('Pase de ejemplo de CAFÉ CENTRAL')
        ->assertSee('El Pase')
        ->assertSee('Beneficios')
        ->assertSee('Ejemplo: 1 punto por visita, 2 los martes. Al llegar a 15 puntos antes del plazo, tu cliente obtiene un consumo de cortesía.')
        ->assertDontSee('landing.hero.sample.business')
        ->assertDontSee('landing.hero.card_aria')
        ->assertDontSee('tarjeta Wallet');
});

test('design preview is unavailable in every environment', function (): void {
    $this->get('/design-preview')->assertNotFound();
});

test('project pages resolve grouped translations without leaking keys', function (): void {
    expect(__('landing.page_title'))->toBe('FidelitoPass - Dale a tus clientes una razón para volver.');

    $blade = file_get_contents(resource_path('views/pages/⚡landing/landing.blade.php'));
    expect($blade)->not->toMatch('/CAFÉ CENTRAL|RETO ACTUAL|Consigue 15 puntos|9 \/ 15 puntos|Hamburguesa gratis|Código manual|48273|Retos que se renuevan|Progreso en puntos|>\s*El pase\s*</u');

    expect(__('landing.page_description'))->toStartWith('Crea Promociones de puntos y ofrece a tus clientes un Pase');
    expect(__('landing.navigation.page_sections'))->toBe('Secciones de la página');
    expect(__('landing.hero.pass_aria'))->toContain('Pase de ejemplo');
    expect(array_intersect(['card_aria', 'card_caption', 'card_progress', 'card_detail', 'card_badge'], array_keys(__('landing.hero'))))->toBe([]);
    expect(__('business.profile_title'))->toBe('Perfil del negocio');
    expect(__('business.dashboard.page_title'))->toBe('Resumen');

    $this->get(route('home'))
        ->assertSee('aria-label="Secciones de la página"', false)
        ->assertDontSee('landing.page_title')
        ->assertDontSee('landing.page_description')
        ->assertDontSee('landing.navigation.page_sections');

    $business = Business::factory()->create();
    $this->actingAs($business->user);

    $this->get(route('business.edit'))
        ->assertSee('Perfil del negocio - '.config('app.name'))
        ->assertSeeText('Nombre del negocio')
        ->assertSeeText('Zona horaria')
        ->assertDontSee('business.profile_title')
        ->assertDontSee('business.fields.')
        ->assertDontSee('business.profile.');

    expect(__('Log in to your account'))->toBe('Iniciar sesión en tu cuenta');
});

test('project additions no longer occupy the generic JSON catalog', function (): void {
    $catalog = json_decode(file_get_contents(lang_path('es.json')), true, flags: JSON_THROW_ON_ERROR);
    $projectKeys = [
        'Back to dashboard',
        'Business name',
        'Business profile',
        'Business dashboard',
        'Dark mode is always on',
        'Edit business profile',
        'Enter your business name and time zone to continue.',
        'Review your business information.',
        'Save business',
        'Select a time zone',
        'Set up your business',
        'Time zone',
        'The dark theme is active for every account.',
    ];

    expect(array_intersect($projectKeys, array_keys($catalog)))->toBe([]);
    expect(__('appearance.dark_mode_heading'))->toBe('El modo oscuro está siempre activo');
    expect(__('appearance.dark_mode_description'))->toBe('El tema oscuro está activo para todas las cuentas.');
    expect(__('Appearance'))->toBe('Apariencia');
    expect(__('Appearance settings'))->toBe('Configuración de apariencia');
    expect(__('business.fields.business_name'))->toBe('Nombre del negocio');
    expect(__('business.fields.time_zone'))->toBe('Zona horaria');
    expect(__('business.fields.select_time_zone'))->toBe('Selecciona una zona horaria');
    expect(__('business.profile.save'))->toBe('Guardar negocio');
    expect(__('business.profile.back_to_dashboard'))->toBe('Volver al resumen');
    expect(__('business.dashboard.edit_profile'))->toBe('Editar perfil del negocio');
    expect(__('business.dashboard.description'))->toBe('Consulta los datos de tu negocio.');
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

test('promotion draft action resolves its validation messages from the Spanish catalog', function (): void {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $instant = CarbonImmutable::now('UTC');
    $today = $instant->setTimezone($business->timezone)->toDateString();
    $tomorrow = CarbonImmutable::parse($today, $business->timezone)->addDay()->toDateString();
    $clock = Mockery::mock(DatabaseClock::class);
    $clock->shouldReceive('captureForBusinessTimezone')->andReturn([
        'instant' => $instant->toIso8601String(),
        'business_date' => $today,
    ]);
    $this->instance(DatabaseClock::class, $clock);

    $published = new Promotion;
    $published->forceFill([
        'business_id' => $business->id,
        'local_start_date' => null,
        'local_end_date' => null,
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
        'reward_description' => 'Any small coffee and pastry.',
        'status' => PromotionStatus::Published,
        'timezone_snapshot' => $business->timezone,
        'starts_at' => $instant->addDay()->toIso8601String(),
        'ends_at' => $instant->addDays(8)->toIso8601String(),
    ]);
    $published->save();

    $catalogMessages = [
        'business.promotion.draft_edit_only' => 'Solo se pueden editar promociones en borrador.',
        'business.promotion.extra_points_window_order' => 'El horario de puntos extra debe terminar después de su inicio, dentro del mismo día.',
        'business.promotion.extra_points_all_day_conflict' => 'No se puede combinar una regla de día completo con otros horarios en el mismo día.',
        'business.promotion.extra_points_overlap' => 'Los horarios de puntos extra no pueden superponerse.',
    ];
    foreach ($catalogMessages as $key => $expected) {
        expect(__($key))->toBe($expected);
    }

    $messages = [
        'business.promotion.draft_edit_only' => 'Draft edit test translation.',
        'business.promotion.extra_points_window_order' => 'Window order test translation.',
        'business.promotion.extra_points_all_day_conflict' => 'All-day conflict test translation.',
        'business.promotion.extra_points_overlap' => 'Overlap test translation.',
    ];
    $translator = app('translator');
    foreach ($messages as $key => $override) {
        $translator->get($key, [], 'es');
        $translator->addLines([$key => $override], 'es');
    }

    $action = app(SavePromotionDraft::class);
    $validInput = [
        'local_start_date' => $today,
        'local_end_date' => $tomorrow,
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
        'reward_description' => 'Any small coffee and pastry.',
        'extra_points' => [],
    ];

    try {
        $action->handle($owner, $validInput, $published);
        $this->fail('A published Promotion was accepted as a draft.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['promotion'][0])->toBe($messages['business.promotion.draft_edit_only']);
    }

    $invalidWindows = [
        'business.promotion.extra_points_window_order' => [
            ['weekday' => 1, 'start_time' => '10:00', 'end_time' => '09:00', 'multiplier' => 2],
        ],
        'business.promotion.extra_points_all_day_conflict' => [
            ['weekday' => 1, 'start_time' => null, 'end_time' => null, 'multiplier' => 2],
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'multiplier' => 3],
        ],
        'business.promotion.extra_points_overlap' => [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:30', 'multiplier' => 2],
            ['weekday' => 1, 'start_time' => '10:00', 'end_time' => '11:00', 'multiplier' => 3],
        ],
    ];

    foreach ($invalidWindows as $key => $windows) {
        try {
            $action->handle($owner, [...$validInput, 'extra_points' => $windows]);
            $this->fail("Invalid windows for {$key} were accepted.");
        } catch (ValidationException $exception) {
            expect($exception->errors()['extra_points'][0])->toBe($messages[$key]);
        }
    }
});

test('authentication rejection shows a Spanish message', function (): void {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'Estas credenciales no coinciden con nuestros registros.']);
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
