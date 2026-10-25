<?php

use App\Integrations\GoogleWallet\GoogleWalletPassCreator;
use App\Models\Business;
use App\Models\User;
use App\Support\BusinessAcquisitionQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('requires authentication to open the invitation page', function () {
    $this->get('/invite')->assertRedirect(route('login'));
});

it('requires email verification before showing Business invitation context', function () {
    $business = Business::factory()->for(User::factory()->unverified())->create();

    $this->actingAs($business->user)
        ->get('/invite')
        ->assertRedirect(route('verification.notice'));
});

it('returns not found when a verified owner has no Business', function () {
    $this->actingAs(User::factory()->create())
        ->get('/invite')
        ->assertNotFound();
});

it('explains preparation without treating the default preview as saved appearance', function () {
    $business = Business::factory()->create();

    $this->actingAs($business->user)
        ->get('/invite')
        ->assertSee($business->name)
        ->assertSee('Tarjeta sin preparar')
        ->assertSee('Prepara la tarjeta del negocio para que tus clientes puedan guardarla en Google Wallet.')
        ->assertSee('Elige un color y guarda la apariencia de la tarjeta.')
        ->assertSee('Preparar tu tarjeta')
        ->assertSee('href="'.route('business.pass.appearance').'"', false)
        ->assertDontSee('Descargar QR')
        ->assertDontSee('Apariencia guardada');

    expect($business->fresh()->pass_background_color)->toBeNull();
});

it('keeps saved appearance eligible independently of an active Promotion', function (bool $activePromotion) {
    $this->travelTo(now()->setDate(2030, 1, 2)->startOfDay());
    $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);

    if ($activePromotion) {
        $business->promotions()->create([
            'local_start_date' => '2030-01-01',
            'local_end_date' => '2030-01-03',
            'target_points' => 8,
            'reward_title' => 'Un café',
        ])->forceFill([
            'status' => 'published',
            'local_start_date' => null,
            'local_end_date' => null,
            'timezone_snapshot' => 'Europe/Madrid',
            'starts_at' => '2030-01-01 00:00:00+00',
            'ends_at' => '2030-01-04 00:00:00+00',
        ])->save();
    }

    $this->actingAs($business->user)
        ->get('/invite')
        ->assertSee('Tus clientes escanean este QR y guardan su tarjeta en Google Wallet.')
        ->assertSee('Imprime el QR y colócalo junto a tu caja.')
        ->assertDontSee('No necesitas una promoción activa')
        ->assertDontSee('con o sin una promoción activa')
        ->assertDontSee('Preparar tu tarjeta')
        ->assertDontSee('Apariencia guardada')
        ->assertDontSee('Un café');
})->with(['without a Promotion' => false, 'with an active Promotion' => true]);

it('ignores forged tenant context and escapes the current Business name', function () {
    $business = Business::factory()->create(['name' => '<script>ownerName()</script>']);
    $foreign = Business::factory()->create(['name' => 'Foreign private Business']);
    $actor = $business->user;
    $actor->setRelation('business', $foreign);

    $this->actingAs($actor)
        ->get('/invite?'.http_build_query(['business_id' => $foreign->id, 'business' => $foreign->id]))
        ->assertSee($business->name)
        ->assertDontSee($business->name, false)
        ->assertDontSee($foreign->name);
});

it('reloads current owned appearance on subsequent Livewire requests', function () {
    $business = Business::factory()->create();
    $page = Livewire::actingAs($business->user)
        ->test('pages::business.invitation')
        ->assertSee('Tarjeta sin preparar');

    $business->update(['name' => 'Renamed Business', 'pass_background_color' => '#A77BFF']);

    $page->refresh()
        ->assertSee('Renamed Business')
        ->assertSee('Tus clientes escanean este QR y guardan su tarjeta en Google Wallet.')
        ->assertDontSee('Tarjeta sin preparar')
        ->assertDontSee('Preparar tu tarjeta');
});

it('rechecks owner verification on subsequent Livewire requests', function () {
    $business = Business::factory()->create();
    $actor = $business->user;
    $page = Livewire::actingAs($actor)->test('pages::business.invitation');
    $actor->forceFill(['email_verified_at' => null])->save();

    $page->refresh()->assertForbidden();
});

it('uses one named invitation destination from navigation and the saved Pase', function () {
    $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
    $this->actingAs($business->user);

    expect(route('business.invite', absolute: false))->toBe('/invite');

    $this->get(route('business.pass'))
        ->assertSee('href="'.route('business.invite').'"', false)
        ->assertDontSee('/pass/invite', false);

    $response = $this->get(route('business.invite'));
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);

    expect($xpath->query('//nav//a[@aria-current="page"]')->length)->toBe(1)
        ->and($xpath->query('//nav//a[@aria-current="page"]')->item(0)->getAttribute('href'))
        ->toBe(route('business.invite'));

    $this->get('/pass/invite')->assertNotFound();
});

it('reserves the QR area with one contextual appearance or download action', function (?string $color, int $recoveryLinks) {
    $business = Business::factory()->create(['pass_background_color' => $color]);

    $response = $this->actingAs($business->user)->get('/invite');
    $response->assertSee('Cómo invitar a tus clientes')
        ->assertSee('Coloca el QR en tu negocio')
        ->assertSee('Tus clientes guardan la tarjeta')
        ->assertSee('Confirma cada visita')
        ->assertSee($color === null ? 'Preparar tu tarjeta' : 'Descargar QR')
        ->assertDontSee('Falta integrar')
        ->assertDontSee('maqueta')
        ->assertDontSee('Apariencia guardada');
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);

    expect($xpath->query('//main//canvas | //main//a[@download] | //main//*[@data-flux-badge]')->length)->toBe(0)
        ->and($xpath->query('//main//img[starts-with(@src,"data:image/svg+xml;base64,")]')->length)->toBe($color === null ? 0 : 1)
        ->and($xpath->query('//main//*[@role="group" and @aria-label="Vista del QR del negocio"]')->length)->toBe(1)
        ->and($xpath->query('//main//button')->length)->toBe($color === null ? 0 : 1)
        ->and($xpath->query('//main//button[@disabled and @aria-describedby="invitation-action-help"]')->length)->toBe(0)
        ->and($xpath->query('//*[@id="invitation-action-help"]//svg[@aria-hidden="true"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="invitation-action-help"]//p')->length)->toBe(1)
        ->and($xpath->query('//section[@aria-labelledby="invitation-instructions-heading"]/figure//img')->length)->toBe(1)
        ->and($xpath->query('//section[@aria-labelledby="invitation-instructions-heading"]/figure//img')->item(0)?->getAttribute('src'))->toBe(asset('table_tend_card.png'))
        ->and($xpath->query('//section[@aria-labelledby="invitation-instructions-heading"]/figure//img')->item(0)?->getAttribute('alt'))->toBe('Ejemplo de un cartel de sobremesa con el QR del negocio')
        ->and($xpath->query('//section[@aria-labelledby="invitation-instructions-heading"]/figure/preceding-sibling::ol')->length)->toBe(1)
        ->and($xpath->query('//section[@aria-labelledby="invitation-instructions-heading"]/figure/figcaption')->item(0)?->textContent)->toContain('Para imprimir, usa siempre el QR de tu negocio.')
        ->and($xpath->query('//main//ol/li')->length)->toBe(3)
        ->and($xpath->query('//main//ol/li//svg[@aria-hidden="true"]')->length)->toBe(3)
        ->and($xpath->query('//main//a')->length)->toBe($recoveryLinks);
    if ($color === null) {
        expect($xpath->query('//main//a[@aria-describedby="invitation-action-help"]')->item(0)->getAttribute('href'))
            ->toBe(route('business.pass.appearance'));
    }
})->with(['unsaved appearance' => [null, 1], 'saved appearance' => ['#A77BFF', 0]]);

it('does not mutate domain records while opening and refreshing the invitation', function () {
    $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
    $pass = $business->customerPasses()->create(['wallet_object_id' => 'issuer.existing']);
    $promotion = $business->promotions()->create([
        'local_start_date' => '2030-01-01',
        'local_end_date' => '2030-01-03',
        'target_points' => 8,
        'reward_title' => 'Existing draft',
    ]);
    $businessBefore = $business->fresh()->getRawOriginal();
    $passBefore = $pass->fresh()->getRawOriginal();
    $promotionBefore = $promotion->fresh()->getRawOriginal();
    Queue::fake();
    $this->mock(GoogleWalletPassCreator::class)
        ->shouldReceive('create')
        ->never();
    $writes = [];
    $captureWrites = true;
    DB::listen(function ($query) use (&$writes, &$captureWrites) {
        if ($captureWrites && preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->actingAs($business->user)->get('/invite')->assertOk();
    Livewire::actingAs($business->user)->test('pages::business.invitation')->refresh()
        ->call('downloadQr')->assertFileDownloaded();
    $captureWrites = false;

    expect($writes)->toBeEmpty()
        ->and($business->fresh()->getRawOriginal())->toBe($businessBefore)
        ->and($pass->fresh()->getRawOriginal())->toBe($passBefore)
        ->and($promotion->fresh()->getRawOriginal())->toBe($promotionBefore);
    Queue::assertNothingPushed();
});

it('downloads exactly the displayed preparation SVG for the owner despite forged tenant arguments', function () {
    $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
    $foreign = Business::factory()->create(['pass_background_color' => '#A77BFF']);
    $svg = app(BusinessAcquisitionQr::class)->svg($business);
    $actor = $business->user;
    $actor->setRelation('business', $foreign);

    Livewire::actingAs($actor)->test('pages::business.invitation')
        ->assertSee('data:image/svg+xml;base64,'.base64_encode($svg), false)
        ->call('downloadQr', $foreign->id)
        ->assertFileDownloaded('fidelitopass-qr-'.$business->public_id.'.svg', $svg, 'image/svg+xml');

    $this->get('/join/'.$business->public_id)
        ->assertSee($business->name)
        ->assertSee(__('join.save'))
        ->assertDontSee(__('join.sample_caption'));
});

it('refuses a download before appearance has been saved', function () {
    $business = Business::factory()->create();

    Livewire::actingAs($business->user)->test('pages::business.invitation')
        ->call('downloadQr')->assertForbidden()->assertNoFileDownloaded();
});

it('rechecks current saved appearance at download time', function () {
    $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
    $page = Livewire::actingAs($business->user)->test('pages::business.invitation');
    $business->update(['pass_background_color' => null]);

    $page->call('downloadQr')->assertForbidden()->assertNoFileDownloaded();
});

it('rechecks current owner verification at download time', function () {
    $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
    $actor = $business->user;
    $page = Livewire::actingAs($actor)->test('pages::business.invitation');
    $actor->forceFill(['email_verified_at' => null])->save();

    $page->call('downloadQr')->assertForbidden()->assertNoFileDownloaded();
});

it('refuses a download after the authenticated actor no longer exists', function () {
    $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
    $page = Livewire::actingAs($business->user)->test('pages::business.invitation');
    auth()->logout();

    $page->call('downloadQr')->assertUnauthorized()->assertNoFileDownloaded();
});

it('recovers from generation failure without reallocating identity or issuing a download', function () {
    $business = Business::factory()->create(['pass_background_color' => '#A77BFF']);
    $identity = $business->public_id;
    Exceptions::fake();
    config(['app.url' => 'not a public origin']);
    $page = Livewire::actingAs($business->user)->test('pages::business.invitation')
        ->assertSee('No pudimos generar el QR. Inténtalo de nuevo.')
        ->assertSee('Reintentar')
        ->assertDontSee('data:image/svg+xml;base64,', false)
        ->call('downloadQr')->assertNoFileDownloaded();

    $document = new DOMDocument;
    @$document->loadHTML($page->html());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//button[@aria-describedby="invitation-action-help" and @disabled]')->length)->toBe(1)
        ->and($xpath->query('//button[normalize-space(.)="Reintentar" and not(@disabled)]')->length)->toBe(1)
        ->and($xpath->query('//a[@aria-describedby="invitation-action-help"]')->length)->toBe(0);

    config(['app.url' => 'https://acquire.example.test']);
    $page->call('retryQr')
        ->assertDontSee('No pudimos generar el QR. Inténtalo de nuevo.')
        ->assertSee('data:image/svg+xml;base64,', false)
        ->call('downloadQr')->assertFileDownloaded();
    expect($business->fresh()->public_id)->toBe($identity);
    Exceptions::assertReported(InvalidArgumentException::class);
});
