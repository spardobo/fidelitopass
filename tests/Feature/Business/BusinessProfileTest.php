<?php

use App\Models\Business;
use App\Support\SupportedTimezones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders compact timezone options with stored selection and future publication guidance', function () {
    $business = Business::factory()->create(['timezone' => 'America/La_Paz']);
    $this->actingAs($business->user);

    Livewire::test('pages::business.profile')
        ->assertSet('timezone', 'America/La_Paz')
        ->assertSeeHtml('id="business-timezone-name"')
        ->assertSee('Hora de Bolivia')
        ->assertSee('data-timezone-name="Hora estándar de Argentina"', escape: false)
        ->assertSeeHtml('value="Europe/Madrid"')
        ->assertSeeHtml('value="Asia/Tokyo"')
        ->assertSeeHtml('value="UTC"')
        ->assertDontSee('Buscar zona horaria')
        ->assertSee('Edita el nombre y la zona horaria de tu negocio.')
        ->assertSee('Las fechas y los horarios se basan en la hora local de tu negocio. Cambiar la zona horaria solo afectará a las promociones nuevas que publiques.');
});

it('renders the saved timezone name separately from its compact option', function () {
    $business = Business::factory()->create(['timezone' => 'America/La_Paz']);
    $this->actingAs($business->user);
    $component = Livewire::test('pages::business.profile');
    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $xpath = new DOMXPath($document);

    expect(trim($xpath->query('//*[@id="business-timezone-name"]')->item(0)->textContent))->toBe('Hora de Bolivia');
    expect(trim($xpath->query('//select[@id="business-timezone"]/option[@value="America/La_Paz"]')->item(0)->textContent))->toBe('Bolivia, La Paz (UTC-04:00)');
});

it('renders timezone labels using the active locale', function () {
    $business = Business::factory()->create(['timezone' => 'Asia/Kolkata']);
    $this->actingAs($business->user);
    app()->setLocale('en');

    Livewire::test('pages::business.profile')
        ->assertSet('timezone', 'Asia/Kolkata')
        ->assertSee('Bolivia Time')
        ->assertDontSee('Hora de Bolivia');
});

it('rejects PHP only timezones without changing either business field', function () {
    $unsupported = array_values(array_diff(timezone_identifiers_list(), SupportedTimezones::identifiers()));
    if ($unsupported === []) {
        fwrite(STDOUT, "Coverage limit: ICU recognizes every native ID; PHP-only profile rejection has no runtime case.\n");
        expect(SupportedTimezones::identifiers())->toBe(timezone_identifiers_list());

        return;
    }
    $business = Business::factory()->create(['name' => 'Sin cambios', 'timezone' => 'UTC']);
    $this->actingAs($business->user);

    Livewire::test('pages::business.profile')
        ->set('name', 'Cambio rechazado')
        ->set('timezone', $unsupported[0])
        ->call('save')
        ->assertHasErrors(['timezone']);

    expect($business->fresh()->name)->toBe('Sin cambios')
        ->and($business->fresh()->timezone)->toBe('UTC');
});

it('saves the original supported IANA identity without ICU canonical rewriting', function (string $identifier) {
    $business = Business::factory()->create(['timezone' => 'UTC']);
    $this->actingAs($business->user);

    Livewire::test('pages::business.profile')
        ->set('timezone', $identifier)
        ->assertSeeHtml('value="'.$identifier.'"')
        ->set('name', 'Negocio actualizado')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', duration: 5000, slots: ['text' => __('account-feedback.business_saved')], dataset: ['variant' => 'success'])
        ->assertRedirect(route('dashboard'));

    expect($business->fresh()->name)->toBe('Negocio actualizado')
        ->and($business->fresh()->timezone)->toBe($identifier);
})->with(['Europe/Madrid', 'Asia/Kolkata', 'Asia/Kathmandu']);

it('keeps the profile editing heading when an owner clears the name', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);

    Livewire::test('pages::business.profile')
        ->set('name', '')
        ->assertSee('Perfil del negocio')
        ->assertDontSee('Configura tu negocio')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($business->fresh()->name)->toBe($business->name);
});

it('edits the current owners existing business without creating another', function () {
    $ownedBusiness = Business::factory()->create();
    $foreignBusiness = Business::factory()->create(['name' => 'Intocable', 'timezone' => 'UTC']);
    $this->actingAs($ownedBusiness->user);

    Livewire::test('pages::business.profile')
        ->assertSet('name', $ownedBusiness->name)
        ->assertSet('timezone', $ownedBusiness->timezone)
        ->set('name', 'Nuevo nombre')
        ->set('timezone', 'Europe/Madrid')
        ->call('save')
        ->assertRedirect(route('dashboard'));

    expect($ownedBusiness->fresh()->name)->toBe('Nuevo nombre')
        ->and($ownedBusiness->fresh()->timezone)->toBe('Europe/Madrid');

    expect($foreignBusiness->fresh()->name)->toBe('Intocable')
        ->and($foreignBusiness->fresh()->timezone)->toBe('UTC');

    expect(Business::count())->toBe(2);
});

it('does not expose owner or business identifiers as writable profile state', function (string $property) {
    $business = Business::factory()->create();
    $foreignBusiness = Business::factory()->create();
    $this->actingAs($business->user);
    $value = $property === 'user_id' ? $foreignBusiness->user_id : $foreignBusiness->id;

    expect(fn () => Livewire::test('pages::business.profile')->set($property, $value))
        ->toThrow(PublicPropertyNotFoundException::class);
})->with(['user_id', 'business_id']);

it('rejects invalid names and non IANA timezones without changing the profile', function ($name, $timezone, $field) {
    $business = Business::factory()->create(['name' => 'Café Sur', 'timezone' => 'UTC']);
    $this->actingAs($business->user);

    Livewire::test('pages::business.profile')
        ->set('name', $name)
        ->set('timezone', $timezone)
        ->call('save')
        ->assertHasErrors([$field])
        ->assertDispatched('toast-show', slots: ['text' => __('account-feedback.validation')], dataset: ['variant' => 'danger']);

    expect($business->fresh()->name)->toBe('Café Sur')
        ->and($business->fresh()->timezone)->toBe('UTC');
    expect(Business::count())->toBe(1);
})->with([
    'missing name' => ['', 'UTC', 'name'],
    'long name' => [str_repeat('a', 256), 'UTC', 'name'],
    'invalid timezone' => ['Café Sur', 'Mars/Olympus', 'timezone'],
    'ICU alias outside native defaults' => ['Café Sur', 'US/Eastern', 'timezone'],
    'ICU unknown fallback' => ['Café Sur', 'Etc/Unknown', 'timezone'],
    'explicit timezone required' => ['Café Sur', '', 'timezone'],
]);

it('keeps the persisted business identity while the editable name is unsaved', function () {
    $business = Business::factory()->create(['name' => 'Saved Business']);
    $this->actingAs($business->user);

    Livewire::test('pages::business.profile')
        ->set('name', 'Unsaved Business')
        ->assertSee('Saved Business · Tu negocio')
        ->assertDontSee('Unsaved Business · Tu negocio');

    expect($business->fresh()->name)->toBe('Saved Business');
});

it('reports failed business persistence without redirecting or changing stored fields', function () {
    $business = Business::factory()->create(['name' => 'Stored Business']);
    $this->actingAs($business->user);
    Exceptions::fake();
    $dispatcher = Business::getEventDispatcher();
    Business::setEventDispatcher(clone $dispatcher);
    Business::updating(fn () => throw new RuntimeException('Internal persistence failure.'));

    try {
        Livewire::test('pages::business.profile')->set('name', 'Unsaved Business')->call('save')
            ->assertNoRedirect()
            ->assertDispatched('toast-show', slots: ['text' => __('account-feedback.unexpected')], dataset: ['variant' => 'danger'])
            ->assertDontSee('Internal persistence failure.');
    } finally {
        Business::setEventDispatcher($dispatcher);
    }

    Exceptions::assertReported(RuntimeException::class);
    expect($business->fresh()->name)->toBe('Stored Business');
});

it('keeps authorization failures outside recovered business feedback', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);
    $component = Livewire::test('pages::business.profile');
    Gate::before(fn () => false);

    $component->call('save')->assertStatus(403)->assertNotDispatched('toast-show');
});
