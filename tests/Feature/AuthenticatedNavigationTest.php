<?php

use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

it('renders enabled product destinations and POST logout for the authenticated owner', function () {
    $business = Business::factory()->create();

    $response = $this->actingAs($business->user)->get(route('dashboard'));

    $response->assertSee('Resumen')->assertSee('Pase')->assertSee('Invitar clientes')
        ->assertSee('Registrar visita')->assertSee('Abrir menú de cuenta')
        ->assertSee('data-flux-avatar', false);

    $document = new DOMDocument;
    @$document->loadHTML(mb_convert_encoding($response->getContent(), 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($document);

    foreach (['/pass', '/invite', '/visits/create'] as $path) {
        $links = $xpath->query('//header//a[@href="'.url($path).'"]');
        expect($links->length)->toBe(1);
        expect($links->item(0)->hasAttribute('disabled'))->toBeFalse();
        expect($links->item(0)->getAttribute('aria-disabled'))->not->toBe('true');
    }

    foreach (['business.edit', 'profile.edit', 'security.edit'] as $route) {
        expect($xpath->query('//header//a[@href="'.route($route).'"]')->length)->toBe(1);
    }

    expect($xpath->query('//form[@method="POST" and @action="'.route('logout').'"]//button[@type="submit"]')->length)->toBe(1);
});

it('marks only the actual current shell destination', function (string $route) {
    $business = Business::factory()->create();

    $response = $this->actingAs($business->user)
        ->withSession(['auth.password_confirmed_at' => time()])->get(route($route));

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $current = $xpath->query('//header//a[@aria-current="page"]');

    expect($current->length)->toBe(1);
    expect($current->item(0)->getAttribute('href'))->toBe(route($route));
})->with(['dashboard', 'business.edit', 'profile.edit', 'security.edit']);

it('keeps the product section current on pending child destinations', function (string $path, string $destination) {
    app()->instance('request', Request::create($path));

    $html = Blade::render('<x-app-navigation />');
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $current = (new DOMXPath($document))->query('//a[@aria-current="page"]');

    expect($current->length)->toBe(1);
    expect($current->item(0)->getAttribute('href'))->toBe(url($destination));
})->with([
    'appearance' => ['/pass/appearance', '/pass'],
    'promotion editor' => ['/pass/promotions/12/edit', '/pass'],
    'promotion review' => ['/pass/promotions/12/review', '/pass'],
    'promotion detail' => ['/pass/promotions/12', '/pass'],
    'invitation' => ['/invite', '/invite'],
]);

it('preserves password confirmation when security is reached from the shell', function () {
    $business = Business::factory()->create();

    $this->actingAs($business->user)->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));
});
