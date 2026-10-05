<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

        $response->assertOk()
            ->assertSee('Nombre del negocio')
            ->assertSee('Zona horaria')
            ->assertSee('Selecciona una zona horaria')
            ->assertSee('Argentina / Buenos Aires')
            ->assertSee('Las fechas y los horarios tendrán como referencia la hora local de tu negocio.');

        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);

        $this->assertSame(1, $xpath->query('//input[@name="business_name" and @required]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="timezone" and @required]/option[@value="" and @selected]')->length);
        $this->assertSame(0, $xpath->query('//select[@name="timezone"]/option[@value!="" and @selected]')->length);
        $this->assertSame(timezone_identifiers_list(), array_map(
            fn ($option) => $option->getAttribute('value'),
            iterator_to_array($xpath->query('//select[@name="timezone"]/option[@value!=""]')),
        ));
        $this->assertMatchesRegularExpression('/^\(UTC[+-]\d{2}:\d{2}\) Montevideo$/', trim(
            $xpath->query('//select[@name="timezone"]/option[@value="America/Montevideo"]')->item(0)->textContent,
        ));
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
            'business_name' => 'Café del barrio',
            'timezone' => 'America/La_Paz',
        ]);

        $response->assertRedirect(route('register'))
            ->assertSessionHasErrors(['password' => $message])
            ->assertSessionHasInput('name', 'New Owner')
            ->assertSessionHasInput('email', 'owner@example.test');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('businesses', 0);
        $this->assertGuest();
        Notification::assertNothingSent();

        $session = app('session.store');
        $this->withCookie($session->getName(), $session->getId())
            ->get(route('register'))->assertSee($message);
    }

    public function test_new_users_can_register(): void
    {
        Notification::fake();

        $response = $this->post(route('register.store'), $this->registrationInput());

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertDatabaseCount('businesses', 1);
        $this->assertDatabaseHas('businesses', [
            'user_id' => $user->id,
            'name' => 'Café del barrio',
            'timezone' => 'America/La_Paz',
        ]);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    #[DataProvider('invalidBusinessInput')]
    public function test_invalid_business_input_creates_neither_owner_nor_business(array $changes, string $field, string $message): void
    {
        Notification::fake();
        $input = array_replace($this->registrationInput(), $changes);

        $response = $this->from(route('register'))->post(route('register.store'), $input);

        $response->assertRedirect(route('register'))
            ->assertSessionHasErrors([$field => $message])
            ->assertSessionHasInput('name', 'John Doe')
            ->assertSessionHasInput('email', 'test@example.com');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('businesses', 0);
        $this->assertGuest();
        Notification::assertNothingSent();
    }

    public static function invalidBusinessInput(): array
    {
        return [
            'missing business name' => [['business_name' => null], 'business_name', 'El campo nombre del negocio es obligatorio.'],
            'blank business name' => [['business_name' => '   '], 'business_name', 'El campo nombre del negocio es obligatorio.'],
            'non-string business name' => [['business_name' => ['Café']], 'business_name', 'El campo nombre del negocio debe ser una cadena de caracteres.'],
            'oversized business name' => [['business_name' => str_repeat('a', 256)], 'business_name', 'El campo nombre del negocio no debe ser mayor que 255 caracteres.'],
            'missing timezone' => [['timezone' => null], 'timezone', 'El campo zona horaria es obligatorio.'],
            'blank timezone' => [['timezone' => ''], 'timezone', 'El campo zona horaria es obligatorio.'],
            'non-string timezone' => [['timezone' => ['Europe/Madrid']], 'timezone', 'El campo zona horaria debe ser una cadena de caracteres.'],
            'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone', 'El campo zona horaria no está en la lista de valores permitidos.'],
            'offset instead of IANA identifier' => [['timezone' => '+02:00'], 'timezone', 'El campo zona horaria no está en la lista de valores permitidos.'],
        ];
    }

    public function test_omitted_business_fields_are_required_without_timezone_fallback(): void
    {
        Notification::fake();
        $input = $this->registrationInput();
        unset($input['business_name'], $input['timezone']);

        $response = $this->from(route('register'))->post(route('register.store'), $input);

        $response->assertSessionHasErrors(['business_name', 'timezone']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('businesses', 0);
        $this->assertGuest();
        Notification::assertNothingSent();
    }

    public function test_validation_feedback_preserves_business_fields_and_timezone_selection(): void
    {
        Notification::fake();
        $input = $this->registrationInput();
        $input['password_confirmation'] = 'different-password';

        $response = $this->from(route('register'))->post(route('register.store'), $input);

        $response->assertSessionHasInput('business_name', 'Café del barrio')
            ->assertSessionHasInput('timezone', 'America/La_Paz');
        $session = app('session.store');
        $response = $this->withCookie($session->getName(), $session->getId())->get(route('register'));
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);

        $this->assertSame(1, $xpath->query('//select[@name="timezone"]/option[@value="America/La_Paz" and @selected]')->length);
        Notification::assertNothingSent();
    }

    public function test_business_persistence_failure_rolls_back_both_records_without_logging_in_or_notifying(): void
    {
        Notification::fake();
        Exceptions::fake();
        $dispatcher = Business::getEventDispatcher();
        Business::setEventDispatcher(clone $dispatcher);
        $businessWasInserted = false;
        Business::created(function () use (&$businessWasInserted): void {
            $businessWasInserted = true;

            throw new RuntimeException('Business persistence failed.');
        });

        try {
            $response = $this->post(route('register.store'), $this->registrationInput());
        } finally {
            Business::setEventDispatcher($dispatcher);
        }

        $response->assertServerError();
        $this->assertTrue($businessWasInserted);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('businesses', 0);
        $this->assertGuest();
        Notification::assertNothingSent();
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Business persistence failed.');
    }

    public function test_json_registration_preserves_fortify_response_and_ignores_submitted_ownership(): void
    {
        $otherOwner = User::factory()->create();
        Notification::fake();
        $input = array_replace($this->registrationInput(), [
            'user_id' => $otherOwner->id,
            'email_verified_at' => now()->toDateTimeString(),
            'timezone' => 'Europe/Madrid',
        ]);

        $response = $this->postJson(route('register.store'), $input);

        $response->assertCreated();
        $user = User::where('email', 'test@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertDatabaseHas('businesses', ['user_id' => $user->id, 'timezone' => 'Europe/Madrid']);
        $this->assertNull($otherOwner->business);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /** @return array<string, string> */
    private function registrationInput(): array
    {
        return [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'business_name' => 'Café del barrio',
            'timezone' => 'America/La_Paz',
        ];
    }
}
