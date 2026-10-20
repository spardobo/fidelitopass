<?php

use App\Actions\Businesses\UpdatePassAppearance;
use App\Integrations\GoogleWallet\GoogleWalletClient;
use App\Models\Business;
use App\Models\User;
use App\Support\CustomerPassCredentials;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\GoogleWalletHttpFake;

uses(RefreshDatabase::class);

it('distinguishes the personal page title from the business card name', function () {
    $business = Business::factory()->create(['name' => 'Jaqaku']);

    $response = $this->actingAs($business->user)
        ->get(route('business.pass'))
        ->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    expect(trim($xpath->query('//title')->item(0)->textContent))->toBe('Tu Tarjeta - '.config('app.name'));
    expect(trim($xpath->query('//main//h1')->item(0)->textContent))->toBe('Tarjeta Jaqaku');
    expect(trim($xpath->query('//nav[@aria-label="Navegación principal"]//a[@href="'.route('business.pass').'"]')->item(0)->textContent))->toBe('Tu Tarjeta');
    expect($business->fresh()->pass_background_color)->toBeNull();
});

it('uses personal navigation to the owner card without changing destinations', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Ir a tu tarjeta')
        ->assertSee(route('business.pass'), false);

    $this->get(route('business.pass.appearance'))
        ->assertOk()
        ->assertSeeText('Volver a tu tarjeta')
        ->assertSee(route('business.pass'), false);

    $this->get(route('business.promotions.create'))
        ->assertOk()
        ->assertSeeText('Volver a tu tarjeta')
        ->assertSee(route('business.pass'), false);

    expect($business->fresh()->pass_background_color)->toBeNull();
});

it('addresses card configuration to the owner while preserving the customer example', function (bool $saved) {
    $business = Business::factory()->create([
        'name' => 'Jaqaku',
        'pass_background_color' => $saved ? '#A77BFF' : null,
    ]);

    $response = $this->actingAs($business->user)
        ->get(route('business.pass'))
        ->assertOk()
        ->assertSeeText('Tarjeta Jaqaku')
        ->assertSeeText('Recibe una recompensa al completar tu meta.')
        ->assertSeeText('Ahora tu visita vale 3 puntos.');

    if ($saved) {
        $response->assertSeeText('Tarjeta preparada')
            ->assertDontSeeText('Apariencia guardada')
            ->assertSeeText('Tu tarjeta acompaña a tus clientes')
            ->assertSeeText('Tus clientes conservan tu tarjeta. Cuando una promoción termina, la siguiente se presenta en esa misma tarjeta.')
            ->assertSeeText('La primera razón para que tus clientes vuelvan')
            ->assertDontSeeText('Tu primera razón para volver');
    } else {
        $response->assertSeeText('Primero prepara tu tarjeta para crear promociones.')
            ->assertDontSeeText('Tarjeta preparada')
            ->assertDontSeeText('La primera razón para que tus clientes vuelvan');
    }

    expect($business->fresh()->pass_background_color)->toBe($saved ? '#A77BFF' : null);
})->with(['unsaved appearance' => false, 'saved appearance' => true]);

it('shows an unsaved pass appearance without persisting its preview fallback', function () {
    $business = Business::factory()->create();

    $this->actingAs($business->user)
        ->get(route('business.pass'))
        ->assertOk()
        ->assertDontSee('El color lavanda es una propuesta. Se guardará solo cuando lo confirmes.')
        ->assertSee(__('business.pass.preview_unsaved_caption'));

    expect($business->fresh()->pass_background_color)->toBeNull();
});

it('keeps a freshly registered business in the unsaved pass state', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'New Owner',
        'email' => 'new-owner@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'business_name' => 'Café del barrio',
        'timezone' => 'America/La_Paz',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::sole();
    $user->markEmailAsVerified();

    expect($user->business->pass_background_color)->toBeNull();

    $this->actingAs($user)
        ->get(route('business.pass'))
        ->assertOk()
        ->assertSee('Tarjeta sin preparar')
        ->assertDontSee(__('business.pass.saved_status'));
});

it('keeps the pass overview focused on appearance status and promotions', function () {
    $business = Business::factory()->create();

    $this->actingAs($business->user)
        ->get(route('business.pass'))
        ->assertOk()
        ->assertSee(__('business.pass.prepare'))
        ->assertSee('Primero prepara tu tarjeta para crear promociones.')
        ->assertDontSee('pass-background-color')
        ->assertDontSee('pass-background-hex');
});

it('opens a separate appearance editor with a savable fallback draft', function () {
    $http = new GoogleWalletHttpFake;
    app()->instance(GoogleWalletClient::class, new GoogleWalletClient($http->client));
    config(['services.google_wallet.credentials_path' => null, 'services.google_wallet.issuer_id' => '']);
    $business = Business::factory()->create();

    $this->actingAs($business->user)
        ->get(route('business.pass.appearance'))
        ->assertOk()
        ->assertSee(__('business.pass.editor_title'))
        ->assertSee('pass-background-hex')
        ->assertDontSee('data-modal="pass-color-picker"', false);

    Livewire::actingAs($business->user)
        ->test('pages::business.pass')
        ->assertSet('backgroundColor', '#A77BFF')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', duration: 5000, slots: ['text' => 'Apariencia guardada'], dataset: ['variant' => 'success']);

    Livewire::actingAs($business->user)
        ->test('pages::business.pass')
        ->set('backgroundColor', '#E53935')
        ->assertSet('isDirty', true);

    expect($business->fresh()->pass_background_color)->toBe('#A77BFF');
    expect($http->history)->toBeEmpty();
});

it('keeps the untouched fallback draft clean and compares hex values case-insensitively', function () {
    $business = Business::factory()->create();

    Livewire::actingAs($business->user)
        ->test('pages::business.pass')
        ->assertSet('backgroundColor', '#A77BFF')
        ->assertSet('isDirty', false)
        ->set('backgroundColor', '#a77bff')
        ->assertSet('isDirty', false)
        ->set('backgroundColor', '#E53935')
        ->assertSet('isDirty', true);

    $savedBusiness = Business::factory()->create(['pass_background_color' => '#A77BFF']);

    Livewire::actingAs($savedBusiness->user)
        ->test('pages::business.pass')
        ->set('backgroundColor', '#a77bff')
        ->assertSet('isDirty', false);
});

it('uses the application theme and primary action roles on both pass screens', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    $this->get(route('business.pass.appearance'))
        ->assertSee('class="app-theme app-workspace app-pass-page', false)
        ->assertSee('app-button-primary min-h-11', false);

    $this->get(route('business.pass'))
        ->assertSee('class="app-theme app-workspace app-pass-page', false)
        ->assertSee('app-button-primary min-h-11', false)
        ->assertSee('app-pass-overview-content', false)
        ->assertSee(__('business.pass.color_sample_reward'))
        ->assertDontSee('Hamburguesa gratis');

    $this->get(route('business.pass.appearance'))
        ->assertDontSee('app-pass-overview-content', false);
});

it('uses a compact illustrative pass without customer data or credentials', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    foreach ([route('business.pass'), route('business.pass.appearance')] as $route) {
        $response = $this->get($route)
            ->assertSee('app-pass-preview', false)
            ->assertSee('app-pass-preview-header', false)
            ->assertSee('app-role-pass-preview-brand', false)
            ->assertSee('app-role-pass-preview-label', false)
            ->assertSee('app-role-pass-preview-compact', false)
            ->assertSee('app-role-pass-preview-metric', false)
            ->assertSee(__('business.pass.color_sample_progress'))
            ->assertSee(__('business.pass.color_sample_deadline'))
            ->assertDontSee('data-pass', false)
            ->assertDontSee('preview-pass-qr.svg', false)
            ->assertDontSee('landing-pass', false);

        $document = new DOMDocument;
        $previousLibxmlSetting = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlSetting);
        $preview = (new DOMXPath($document))->query(
            '//article[contains(concat(" ", normalize-space(@class), " "), " app-pass-preview ")]'
        )->item(0);

        expect($preview)->not->toBeNull();

        $zones = [];

        foreach ($preview->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $zones[] = strtok($child->getAttribute('class'), ' ');
            }
        }

        expect($zones)->toBe([
            'app-pass-preview-header',
            'app-pass-preview-content',
            'app-pass-preview-reward',
        ]);
    }
});

it('renders the Flux outline preview glyph and decorative inline brand mark', function () {
    $business = Business::factory()->create();

    $response = $this->actingAs($business->user)
        ->get(route('business.pass'))
        ->assertOk();

    $document = new DOMDocument;
    $previousLibxmlSetting = libxml_use_internal_errors(true);
    $document->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlSetting);
    $xpath = new DOMXPath($document);
    $preview = $xpath->query(
        '//article[contains(concat(" ", normalize-space(@class), " "), " app-pass-preview ")]'
    )->item(0);
    $promotionIcon = $xpath->query(
        './/svg[contains(concat(" ", normalize-space(@class), " "), " app-pass-preview-promotion-icon ")]',
        $preview,
    );
    $brandMark = $xpath->query(
        './/svg[contains(concat(" ", normalize-space(@class), " "), " app-pass-brand-mark ")]',
        $preview,
    );

    expect($promotionIcon)->toHaveCount(1);
    expect($brandMark)->toHaveCount(1);
    expect($promotionIcon->item(0)->getAttribute('aria-hidden'))->toBe('true');
    expect($promotionIcon->item(0)->getAttribute('viewbox'))->toBe('0 0 24 24');
    expect($promotionIcon->item(0)->getAttribute('stroke-width'))->toBe('1.5');
    expect($promotionIcon->item(0)->getAttribute('fill'))->toBe('none');
    expect($promotionIcon->item(0)->getElementsByTagName('path')->item(0)->getAttribute('d'))
        ->toStartWith('M9.568 3H5.25');
    expect($brandMark->item(0)->getAttribute('aria-hidden'))->toBe('true');
    expect($brandMark->item(0)->getAttribute('focusable'))->toBe('false');
    $menuIcons = $xpath->query('//svg[@data-flux-menu-item-icon]');
    expect($menuIcons->length)->toBeGreaterThan(0);
    foreach ($menuIcons as $menuIcon) {
        expect($menuIcon->getAttribute('viewbox'))->toBe('0 0 24 24');
        expect($menuIcon->getAttribute('stroke-width'))->toBe('1.5');
        expect($menuIcon->getAttribute('fill'))->toBe('none');
    }
    expect(file_exists(public_path('icons/target.svg')))->toBeFalse();
    expect(file_exists(resource_path('views/components/app-icon.blade.php')))->toBeFalse();
    expect(file_exists(public_path('benefit-challenges.svg')))->toBeFalse();
    expect(file_exists(public_path('benefit-points.svg')))->toBeFalse();
});

it('uses shared marketing and preview typography roles on the public landing', function () {
    $response = $this->get(route('home'))
        ->assertDontSee('app-pass-page', false)
        ->assertSee('app-role-marketing-title', false)
        ->assertSee('app-role-marketing-section', false)
        ->assertSee('app-role-pass-preview-brand', false)
        ->assertSee('app-role-pass-preview-label', false)
        ->assertSee('app-role-pass-preview-compact', false)
        ->assertSee('app-role-pass-preview-metric', false)
        ->assertSee('app-role-pass-preview-reward', false)
        ->assertSee('app-role-action!', false)
        ->assertSee('min-h-app-control', false)
        ->assertSee('app-pass-preview-reward', false)
        ->assertSee('app-pass-preview--marketing', false)
        ->assertSee('app-pass-preview--with-qr', false)
        ->assertSee('app-pass-preview-qr', false)
        ->assertSee('Un consumo de cortesía')
        ->assertDontSee('hamburguesa')
        ->assertDontSee('🎯')
        ->assertDontSee('⚡')
        ->assertDontSee('🎁');

    $document = new DOMDocument;
    $previousLibxmlSetting = libxml_use_internal_errors(true);
    $document->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlSetting);
    $xpath = new DOMXPath($document);
    $preview = $xpath->query('//article[@data-pass]')->item(0);
    $middle = $xpath->query(
        './/div[contains(concat(" ", normalize-space(@class), " "), " app-pass-preview-content ")]',
        $preview,
    )->item(0);
    $qr = $xpath->query(
        './/aside[contains(concat(" ", normalize-space(@class), " "), " app-pass-preview-qr ")]',
        $middle,
    );

    expect($preview)->not->toBeNull();
    expect($middle)->not->toBeNull();
    expect($qr)->toHaveCount(1);

    $landingZones = [];

    foreach ($preview->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $landingZones[] = strtok($child->getAttribute('class'), ' ');
        }
    }

    expect($landingZones)->toBe([
        'app-pass-preview-header',
        'app-pass-preview-content',
        'app-pass-preview-reward',
    ]);
});

it('shows sample promotion terms without implying current activity or customer credentials', function () {
    $unsavedBusiness = Business::factory()->create();

    foreach ([route('business.pass'), route('business.pass.appearance')] as $route) {
        $response = $this->actingAs($unsavedBusiness->user)
            ->get($route)
            ->assertOk()
            ->assertSee(__('business.pass.color_sample_label'))
            ->assertSee(__('business.pass.color_sample_description'))
            ->assertSee(__('business.pass.color_sample_progress'))
            ->assertSee(__('business.pass.color_sample_extra_points'))
            ->assertSee(__('business.pass.color_sample_reward'))
            ->assertSee('app-role-pass-preview-reward', false)
            ->assertSee(__('business.pass.color_sample_reward'))
            ->assertDontSee('Hamburguesa gratis')
            ->assertSee(__('business.pass.color_sample_deadline'))
            ->assertDontSee('48273')
            ->assertDontSee('Código manual')
            ->assertDontSee('logo_icon.svg')
            ->assertDontSee('data:image/png;base64');

        expect(substr_count($response->getContent(), __('business.pass.preview_unsaved_caption')))->toBe(1);
        expect(substr_count($response->getContent(), __('business.pass.preview_saved_caption')))->toBe(0);
    }

    $savedBusiness = Business::factory()->create(['pass_background_color' => '#A77BFF']);

    foreach ([route('business.pass'), route('business.pass.appearance')] as $route) {
        $response = $this->actingAs($savedBusiness->user)
            ->get($route)
            ->assertOk()
            ->assertSee(__('business.pass.color_sample_label'))
            ->assertSee(__('business.pass.color_sample_progress'))
            ->assertSee(__('business.pass.color_sample_extra_points'))
            ->assertSee(__('business.pass.color_sample_deadline'))
            ->assertDontSee(__('business.pass.preview_unsaved_caption'));

        expect(substr_count($response->getContent(), __('business.pass.preview_saved_caption')))->toBe(1);
        expect(substr_count($response->getContent(), __('business.pass.preview_unsaved_caption')))->toBe(0);
    }
});

it('uses the reusable preview and container-sized responsive layouts', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    $this->get(route('business.pass.appearance'))
        ->assertSee('app-pass-preview', false)
        ->assertSee('app-pass-editor-layout', false)
        ->assertSee('app-role-pass-preview-compact', false)
        ->assertSee(__('business.pass.preview_unsaved_caption'))
        ->assertDontSee(__('business.pass.preview_saved_caption'));

    $savedBusiness = Business::factory()->create(['pass_background_color' => '#A77BFF']);

    $this->actingAs($savedBusiness->user)
        ->get(route('business.pass'))
        ->assertSee('app-pass-overview-layout', false)
        ->assertSee(__('business.pass.preview_saved_caption'));
});

it('places one color error region after the complete control row without redundant help text', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    $response = $this->get(route('business.pass.appearance'))
        ->assertOk()
        ->assertDontSee('Ingresa un color hexadecimal de seis dígitos. El contraste del texto de esta vista previa se ajusta automáticamente.');

    $html = $response->getContent();
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $colorRow = $xpath->query('//fieldset[.//input[@id="pass-background-hex"]]')->item(0);
    $errorsBelowColorRow = $colorRow === null
        ? 0
        : $xpath->query('./following-sibling::*[@id="pass-color-error"]', $colorRow)->length;

    expect($xpath->query('//*[@id="pass-color-error" and @role="alert"]')->length)->toBe(1)
        ->and($errorsBelowColorRow)->toBe(1);
});

it('provides a Flux discard dialog instead of browser confirmation', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user)
        ->get(route('business.pass.appearance'))
        ->assertOk()
        ->assertSee('data-modal="discard-pass-appearance"', false)
        ->assertSee(__('business.pass.discard_title'))
        ->assertSee(__('business.pass.continue_editing'))
        ->assertDontSee('wire:confirm');

    Livewire::actingAs($business->user)
        ->test('pages::business.pass')
        ->assertSet('isDirty', false)
        ->set('backgroundColor', '#E53935')
        ->assertSet('isDirty', true);
});

it('shows promotion creation navigation only after the appearance is saved', function () {
    $unsavedBusiness = Business::factory()->create();
    $this->actingAs($unsavedBusiness->user)
        ->get(route('business.pass'))
        ->assertSee(__('business.pass.promotions_empty_heading'))
        ->assertSee(__('business.pass.promotions_prerequisite'))
        ->assertDontSee(__('business.pass.create_first_promotion'));

    $savedBusiness = Business::factory()->create(['pass_background_color' => '#A77BFF']);
    $this->actingAs($savedBusiness->user)
        ->get(route('business.pass'))
        ->assertSee(__('business.pass.create_first_promotion'))
        ->assertSee('/promotions/create', false)
        ->assertSee(__('business.pass.invite_customers'))
        ->assertSee(route('business.invite'), false);
});

it('leaves an unset appearance unset when the editor is cancelled', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    Livewire::test('pages::business.pass')
        ->set('backgroundColor', '#E53935')
        ->call('cancel')
        ->assertSet('backgroundColor', '#A77BFF');

    expect($business->fresh()->pass_background_color)->toBeNull();
});

it('saves a valid appearance and restores the persisted value when cancelled', function () {
    $business = Business::factory()->create();
    $otherBusiness = Business::factory()->create(['pass_background_color' => '#000000']);
    $credentials = CustomerPassCredentials::prepare($business);
    $pass = $business->customerPasses()->make([
        'wallet_object_id' => '123456.pass_'.$credentials->publicPassId,
        'manual_code' => $credentials->manualCode,
        'validation_token_hash' => $credentials->hashValidationToken(),
    ]);
    $pass->public_id = $credentials->publicPassId;
    $pass->save();
    $passFacts = $pass->fresh()->getAttributes();
    $otherFacts = $otherBusiness->fresh()->getAttributes();

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    File::ensureDirectoryExists(base_path('tmp/pass-appearance-wallet-sync'), 0700);
    $credentialsPath = tempnam(base_path('tmp/pass-appearance-wallet-sync'), 'appearance-key-');
    file_put_contents($credentialsPath, json_encode([
        'type' => 'service_account',
        'client_email' => 'wallet@platform.iam.gserviceaccount.com',
        'private_key' => $pem,
    ]));
    config([
        'app.url' => 'https://passes.example.test',
        'services.google_wallet.issuer_id' => '123456',
        'services.google_wallet.credentials_path' => $credentialsPath,
        'services.google_wallet.connect_timeout' => 3,
        'services.google_wallet.timeout' => 10,
    ]);
    $http = new GoogleWalletHttpFake;
    app()->instance(GoogleWalletClient::class, new GoogleWalletClient($http->client));
    $classId = '123456.business_'.$business->public_id;
    $url = 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/'.$classId;
    $remote = ['id' => $classId, 'reviewStatus' => 'approved', 'hexBackgroundColor' => '#2C3E50'];
    $http->respondWith([
        'https://oauth2.googleapis.com/token' => GoogleWalletHttpFake::json([
            'access_token' => 'synthetic-access', 'expires_in' => 3600,
        ]),
        $url => function (Request $request) use (&$remote) {
            if ($request->method() === 'PATCH') {
                $remote['hexBackgroundColor'] = $request->data()['hexBackgroundColor'];
            }

            return GoogleWalletHttpFake::json($remote);
        },
    ]);
    Log::partialMock()->shouldReceive('log')->byDefault();
    $this->actingAs($business->user);

    Livewire::test('pages::business.pass')
        ->set('backgroundColor', '#a77bff')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    expect($business->fresh()->pass_background_color)->toBe('#A77BFF');

    Livewire::test('pages::business.pass')
        ->assertSet('backgroundColor', '#A77BFF')
        ->set('backgroundColor', '#E53935')
        ->call('cancel')
        ->assertSet('backgroundColor', '#A77BFF');

    $this->get(route('business.pass'))->assertOk()->assertSee('#A77BFF');

    expect($remote['hexBackgroundColor'])->toBe('#A77BFF');
    expect($http->requests()->map(fn (array $pair) => $pair[0]->method())->all())
        ->toBe(['POST', 'GET', 'PATCH']);
    expect($http->requests(fn (Request $request) => $request->method() === 'PATCH')->sole()[0]->data())
        ->toBe(['hexBackgroundColor' => '#A77BFF', 'reviewStatus' => 'UNDER_REVIEW']);
    expect($pass->fresh()->getAttributes())->toBe($passFacts);
    expect($otherBusiness->fresh()->getAttributes())->toBe($otherFacts);

    $http->respondWith([$url => fn (Request $request) => $request->method() === 'PATCH'
        ? GoogleWalletHttpFake::json(['error' => 'private-provider-marker'], 503)
        : GoogleWalletHttpFake::json($remote)]);
    Livewire::test('pages::business.pass')
        ->set('backgroundColor', '#E53935')
        ->call('save')
        ->assertHasErrors(['appearance'])
        ->assertSet('backgroundColor', '#E53935')
        ->assertNoRedirect()
        ->assertDispatched('toast-show', fn (string $event, array $parameters): bool => $parameters['slots']['text'] === __('business.pass.save_attention')
            && $parameters['dataset']['variant'] === 'danger')
        ->assertNotDispatched('toast-show', fn (string $event, array $parameters): bool => ($parameters['dataset']['variant'] ?? null) === 'success')
        ->assertDontSee('private-provider-marker');

    expect($business->fresh()->pass_background_color)->toBe('#A77BFF');
    expect($pass->fresh()->getAttributes())->toBe($passFacts);
    expect($otherBusiness->fresh()->getAttributes())->toBe($otherFacts);
    Log::shouldHaveReceived('log')->once()->with('error', 'google_wallet.synchronization_failed', Mockery::on(
        fn (array $context): bool => $context['business_id'] === $business->id
            && $context['actor_id'] === $business->user_id
            && $context['reason'] === 'provider_rejected'
            && $context['stage'] === 'class_update'
            && $context['outcome'] === 'unknown'
            && ! str_contains(json_encode($context), 'private-provider-marker'),
    ));
    expect($http->requests(fn (Request $request) => str_contains($request->url(), 'loyaltyObject')))->toBeEmpty();
    expect($http->requests(fn (Request $request) => $request->method() === 'PATCH'))->toHaveCount(2);

    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $observer = clone $dispatcher;
    $native = null;
    $observer->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$native): void {
        if (str_starts_with($query->sql, 'update "businesses"')) {
            $native = new QueryException('pgsql', 'update private-sql-marker set token = ?', ['private-binding-marker'], new PDOException('private-driver-marker'));

            throw $native;
        }
    });
    $connection->setEventDispatcher($observer);
    $payload = null;
    $requestsBeforeSqlFailure = count($http->history);
    Log::shouldReceive('log')->once()->with('error', 'pass_appearance.save_failed', Mockery::capture($payload));

    try {
        Livewire::test('pages::business.pass')
            ->set('backgroundColor', '#1E88E5')
            ->call('save')
            ->assertHasErrors(['appearance'])
            ->assertSet('backgroundColor', '#1E88E5')
            ->assertNoRedirect()
            ->assertDispatched('toast-show', fn (string $event, array $parameters): bool => $parameters['slots']['text'] === __('business.pass.save_attention')
                && $parameters['dataset']['variant'] === 'danger')
            ->assertNotDispatched('toast-show', fn (string $event, array $parameters): bool => ($parameters['dataset']['variant'] ?? null) === 'success')
            ->assertDontSee('private-sql-marker')
            ->assertDontSee('private-binding-marker')
            ->assertDontSee('private-driver-marker');
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }

    expect($business->fresh()->pass_background_color)->toBe('#A77BFF');
    expect($pass->fresh()->getAttributes())->toBe($passFacts);
    expect($otherBusiness->fresh()->getAttributes())->toBe($otherFacts);
    expect($http->history)->toHaveCount($requestsBeforeSqlFailure);
    expect($native)->toBeInstanceOf(QueryException::class);
    expect($payload)->toHaveKeys(['previous_class', 'previous_file', 'previous_line', 'previous_frames']);
    expect($payload['previous_class'])->toBe(QueryException::class);
    expect($payload['previous_file'])->toBe($native->getFile());
    expect($payload['previous_line'])->toBe($native->getLine());
    expect($payload['previous_frames'])->toBe(array_map(
        static fn (array $frame): array => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function'])),
        $native->getTrace(),
    ));
    expect(array_column($payload['previous_frames'], 'class'))->toContain(UpdatePassAppearance::class);
    expect($payload['provider_confirmed'])->toBeFalse();
    expect(array_diff(array_keys($payload), [
        'previous_class', 'previous_file', 'previous_line', 'previous_frames',
        'provider_confirmed', 'actor_id', 'business_id', 'reason',
    ]))->toBeEmpty();
    expect(json_encode($payload))->not->toContain('private-sql-marker', 'private-binding-marker', 'private-driver-marker');
    Log::shouldHaveReceived('log')->twice();
});

it('rejects malformed appearance colors without changing the saved value', function () {
    $business = Business::factory()->create(['pass_background_color' => '#2C3E50']);
    $this->actingAs($business->user);

    Livewire::test('pages::business.pass')
        ->set('backgroundColor', '#12')
        ->call('save')
        ->assertHasErrors(['backgroundColor'])
        ->assertNotDispatched('toast-show');

    expect($business->fresh()->pass_background_color)->toBe('#2C3E50');
});

it('keeps pass preview text at accessible contrast across presets and boundary colors', function () {
    $business = Business::factory()->create();
    $relativeLuminance = static function (string $color): float {
        $channels = [
            hexdec(substr($color, 1, 2)) / 255,
            hexdec(substr($color, 3, 2)) / 255,
            hexdec(substr($color, 5, 2)) / 255,
        ];
        $linear = array_map(
            static fn (float $channel): float => $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    };
    $contrast = static function (string $first, string $second) use ($relativeLuminance): float {
        $firstLuminance = $relativeLuminance($first);
        $secondLuminance = $relativeLuminance($second);
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    };
    $colors = [
        '#A77BFF', '#E53935', '#2C3E50', '#1E88E5', '#43A047', '#8E24AA', '#FB8C00', '#000000',
        '#FFFFFF', '#777777', '#FFFF00', '#00FFFF', '#FF00FF', '#808080', '#101010',
    ];

    $component = Livewire::actingAs($business->user)->test('pages::business.pass');

    foreach ($colors as $color) {
        $darkInk = '#17131F';
        $expectedInk = $contrast($darkInk, $color) >= 4.5
            ? $darkInk
            : ($contrast('#FFFFFF', $color) >= 4.5 ? '#FFFFFF' : '#000000');

        expect($contrast($expectedInk, $color))->toBeGreaterThanOrEqual(4.5);
        $component->set('backgroundColor', $color)->assertSee("color: {$expectedInk}");
    }
});

it('rechecks business ownership before saving an appearance', function () {
    $business = Business::factory()->create();
    $component = Livewire::actingAs($business->user)
        ->test('pages::business.pass')
        ->set('backgroundColor', '#E53935');
    $newOwner = User::factory()->create();

    $business->newQuery()->whereKey($business->getKey())->update(['user_id' => $newOwner->id]);

    expect(fn () => $component->call('save'))->toThrow(ModelNotFoundException::class);
    expect($business->fresh()->pass_background_color)->toBeNull();
});

it('sends anonymous and unverified visitors through the protected page boundary', function () {
    foreach ([route('business.pass'), route('business.pass.appearance')] as $route) {
        $this->get($route)->assertRedirect(route('login'));
    }

    $unverifiedOwner = User::factory()->unverified()->create();
    Business::factory()->for($unverifiedOwner)->create();

    foreach ([route('business.pass'), route('business.pass.appearance')] as $route) {
        $this->actingAs($unverifiedOwner)
            ->get($route)
            ->assertRedirect(route('verification.notice'));
    }
});

it('selects a preset preview color without persisting appearance', function () {
    $business = Business::factory()->create();

    Livewire::actingAs($business->user)
        ->test('pages::business.pass')
        ->call('selectColor', '#E53935')
        ->assertHasNoErrors()
        ->assertSet('backgroundColor', '#E53935');

    expect($business->fresh()->pass_background_color)->toBeNull();
});

it('renders a captioned sample promotion with simulated sample progress and no credentials', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    foreach ([route('business.pass'), route('business.pass.appearance')] as $route) {
        $this->get($route)
            ->assertOk()
            ->assertSee(__('business.pass.color_sample_label'))
            ->assertSee(__('business.pass.color_sample_description'))
            ->assertSee(__('business.pass.color_sample_progress'))
            ->assertSee(__('business.pass.color_sample_extra_points'))
            ->assertSee(__('business.pass.color_sample_reward'))
            ->assertSee(__('business.pass.color_sample_reward'))
            ->assertDontSee('Hamburguesa gratis')
            ->assertSee(__('business.pass.color_sample_deadline'))
            ->assertDontSee('48273')
            ->assertDontSee('preview-pass-qr.svg')
            ->assertDontSee('Código manual')
            ->assertDontSee('🎁')
            ->assertSee(__('business.pass.preview_unsaved_caption'))
            ->assertDontSee(__('business.pass.preview_saved_caption'));
    }
});

it('provides an accessible native color input beside the synchronized hexadecimal field', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user)
        ->get(route('business.pass.appearance'))
        ->assertOk()
        ->assertSee('app-pass-color-control-row', false)
        ->assertSee('app-pass-color-preset-list', false)
        ->assertSee('app-pass-color-preset', false)
        ->assertSee('id="pass-background-color"', false)
        ->assertSee('type="color"', false)
        ->assertSee('wire:model.live="backgroundColor"', false)
        ->assertSee(__('business.pass.color_picker_label'))
        ->assertDontSee('data-modal="pass-color-picker"', false)
        ->assertDontSee('type="range"', false)
        ->assertSee(__('business.pass.preset_lavender'))
        ->assertSee(__('business.pass.preset_red'))
        ->assertSee(__('business.pass.preset_slate'))
        ->assertSee(__('business.pass.preset_blue'))
        ->assertSee(__('business.pass.preset_green'))
        ->assertSee(__('business.pass.preset_violet'))
        ->assertSee(__('business.pass.preset_orange'))
        ->assertSee(__('business.pass.preset_black'))
        ->assertSee(__('business.pass.shared_appearance_note'))
        ->assertSee(__('business.pass.hex_label'));

    $html = $this->get(route('business.pass.appearance'))->getContent();
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    expect(substr_count($html, 'wire:model.live="backgroundColor"'))
        ->toBe(2)
        ->and($xpath->query('//input[@id="pass-background-color"]')->length)
        ->toBe(1)
        ->and($xpath->query('//fieldset[contains(@class, "app-pass-color-control-row")]//button[@aria-pressed]')->length)
        ->toBe(8)
        ->and($xpath->query('//label[@for="pass-background-color"]')->length)
        ->toBe(1)
        ->and($xpath->query('//ui-close[contains(@class, "w-full") and contains(@class, "sm:w-auto")]')->length)
        ->toBeGreaterThanOrEqual(1)
        ->and(substr_count($html, __('business.pass.shared_appearance_note')))
        ->toBe(1);
});

it('rechecks business ownership before adjusting an appearance color', function () {
    $business = Business::factory()->create();
    $component = Livewire::actingAs($business->user)->test('pages::business.pass');
    $newOwner = User::factory()->create();

    $business->newQuery()->whereKey($business->getKey())->update(['user_id' => $newOwner->id]);

    expect(fn () => $component->call('selectColor', '#123456'))
        ->toThrow(ModelNotFoundException::class);
    expect($business->fresh()->pass_background_color)->toBeNull();
});
