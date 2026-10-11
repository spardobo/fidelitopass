<?php

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Support\BusinessAcquisitionQr;
use App\Support\DatabaseClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('uses one ordered illustrative card across all four callers with three distinct examples', function () {
    $business = Business::factory()->create(['name' => 'Example Business']);
    $responses = [
        $this->get(route('home')),
        $this->get('/join/'.$business->public_id),
        $this->actingAs($business->user)->get(route('business.pass')),
        $this->get(route('business.pass.appearance')),
    ];
    $examples = [
        ['CAFÉ CENTRAL', 'Un consumo de cortesía', 'Disfruta de un consumo de cortesía al completar tu meta.', '9 / 15 puntos', 'Válido hasta el 30 de septiembre'],
        ['Example Business', 'Un beneficio especial', 'Accede a un beneficio exclusivo al completar tu meta.', '3 / 10 puntos', 'Válido hasta el 15 de octubre'],
        ['Example Business', 'Una recompensa especial', 'Recibe una recompensa al completar tu meta.', '4 / 12 puntos', 'Válido hasta el 20 de noviembre'],
        ['Example Business', 'Una recompensa especial', 'Recibe una recompensa al completar tu meta.', '4 / 12 puntos', 'Válido hasta el 20 de noviembre'],
    ];
    $structures = [];
    foreach ($responses as $index => $response) {
        $response->assertOk();
        if ($index < 2) {
            $response->assertSee(asset('preview-pass-qr.svg'), false)->assertSee('48273');
        } else {
            $response->assertDontSee(asset('preview-pass-qr.svg'), false)->assertDontSee('48273')->assertDontSee('Código manual');
        }
        $card = publicJoinExampleCard($response->getContent());
        $text = preg_replace('/\s+/u', ' ', $card->textContent);
        [$name, $reward, $description, $progress, $deadline] = $examples[$index];
        $last = -1;
        foreach ([$name, 'PROMOCIÓN', $reward, $description, $progress, $deadline] as $term) {
            $position = mb_strpos($text, $term);
            expect($position)->not->toBeFalse()->toBeGreaterThan($last);
            $last = $position;
        }
        $structure = [];
        foreach ($card->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $structure[] = explode(' ', $child->getAttribute('class'))[0];
            }
        }
        $structures[] = $structure;
        expect($card->getElementsByTagName('img'))->toHaveCount($index < 2 ? 1 : 0);
        foreach ($card->getElementsByTagName('path') as $path) {
            expect($path->getAttribute('d'))->not->toStartWith('M21 11.25v8.25');
        }
    }
    expect(array_unique(array_map('serialize', $structures)))->toHaveCount(1)
        ->and($structures[0])->toBe(['app-pass-preview-header', 'app-pass-preview-content', 'app-pass-preview-reward']);
    expect(publicJoinExampleCard($responses[2]->getContent())->textContent)
        ->toBe(publicJoinExampleCard($responses[3]->getContent())->textContent);
});

it('uses the shared informational note for the unchanged example caption', function () {
    $business = Business::factory()->create();

    $this->get('/join/'.$business->public_id)
        ->assertOk()
        ->assertSee('class="app-note-with-icon text-app-ink-help"', false)
        ->assertSee('Promoción de ejemplo.')
        ->assertSee('aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center"', false);
});

it('keeps join fictional without Promotion or domain-clock queries across persisted states', function (string $state) {
    $business = Business::factory()->create();
    if ($state === 'draft') {
        $business->promotions()->create(['reward_title' => 'Private persisted reward', 'target_points' => 7, 'local_start_date' => '2090-01-01', 'local_end_date' => '2090-01-02']);
    } elseif ($state !== 'empty') {
        publicJoinPromotion($business, [
            'reward_title' => 'Private persisted reward',
            'starts_at' => $state === 'scheduled' ? '2090-01-01 00:00:00+00' : '2000-01-01 00:00:00+00',
            'ends_at' => $state === 'ended' ? '2000-01-02 00:00:00+00' : '2090-01-02 00:00:00+00',
            'status' => $state === 'cancelled' ? PromotionStatus::Cancelled : PromotionStatus::Published,
            'cancelled_at' => $state === 'cancelled' ? '2000-01-01 01:00:00+00' : null,
        ]);
    }
    $clock = Mockery::mock(DatabaseClock::class);
    $clock->shouldNotReceive('captureForBusinessTimezone');
    $this->instance(DatabaseClock::class, $clock);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (preg_match('/promotions|promotion_multiplier_windows|clock_timestamp/i', $query->sql)) {
            $queries[] = $query->sql;
        }
    });
    $first = $this->get('/join/'.$business->public_id)->assertOk()
        ->assertSee('Promoción de ejemplo.')->assertSee('Un beneficio especial')
        ->assertSee('3 / 10 puntos')->assertSee('Válido hasta el 15 de octubre')
        ->assertDontSee('Private persisted reward')->assertDontSee('Promoción actual')
        ->assertDontSee('Próxima promoción')->assertSee('Hoy tus puntos valen x2');
    $this->travelTo('2095-01-01');
    $second = $this->get('/join/'.$business->public_id)->assertOk();
    expect(publicJoinExampleCard($first->getContent())->textContent)
        ->toBe(publicJoinExampleCard($second->getContent())->textContent)
        ->and($queries)->toBeEmpty();
})->with(['empty', 'draft', 'active', 'scheduled', 'ended', 'cancelled']);

it('omits the optional extra message row rather than rendering an empty placeholder', function () {
    $this->blade('<x-pass-preview business-name="Example" :extra-points="null" />')
        ->assertDontSee('app-pass-preview-extra-points', false)->assertDontSee('Hoy tus puntos valen');
    $this->blade('<x-pass-preview business-name="Example" extra-points="Hoy tus puntos valen x5" />')
        ->assertSee('app-pass-preview-extra-points', false)->assertSee('Hoy tus puntos valen x5');
});

it('places the approved header wordmark before the Business card', function () {
    $business = Business::factory()->create(['name' => 'Café Central']);

    $response = $this->get('/join/'.$business->public_id);

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $logos = $xpath->query('//header//img');
    expect($logos)->toHaveCount(1);
    $logo = $logos->item(0);
    expect($logo->getAttribute('src'))->toBe(asset('logo-header.webp'))
        ->and($logo->getAttribute('alt'))->toBe(__('landing.navigation.logo_alt'))
        ->and($logo->getAttribute('width'))->toBe('480')
        ->and($logo->getAttribute('height'))->toBe('105');
    expect($xpath->query('//header/following-sibling::main//article'))->toHaveCount(1);
    expect($xpath->query('//header//a[@href="'.route('home').'"]//img'))->toHaveCount(1);
    expect($xpath->query('//footer'))->toHaveCount(0);
});

it('opens the permanent acquisition URL anonymously without requiring saved appearance', function (?string $color) {
    $business = Business::factory()->create(['name' => 'Café Central', 'pass_background_color' => $color]);

    $this->get(app(BusinessAcquisitionQr::class)->url($business))
        ->assertSee('Café Central')
        ->assertSee('Promoción de ejemplo.')
        ->assertDontSee('Tu Pase ya está listo.')
        ->assertDontSee('Google Wallet')
        ->assertDontSee('Crear cuenta');

    $this->assertGuest();
})->with(['unsaved appearance' => null, 'saved appearance' => '#A77BFF']);

it('returns 404 for missing malformed internal and Promotion identifiers', function (string $selection) {
    $business = Business::factory()->create(['name' => 'Hidden Business']);
    $promotion = publicJoinPromotion($business);
    $identifier = match ($selection) {
        'missing' => '00000000-0000-7000-8000-000000000000',
        'internal' => (string) $business->id,
        'promotion' => $promotion->public_id,
        default => "' OR 1=1 --",
    };

    $this->get('/join/'.rawurlencode($identifier))
        ->assertNotFound()->assertDontSee('Hidden Business');
})->with(['missing', 'malformed', 'internal', 'promotion']);

it('ignores forged Business selection and never substitutes a foreign active Promotion', function () {
    $business = Business::factory()->create(['name' => 'Selected Business']);
    $foreign = Business::factory()->create(['name' => 'Other Business']);
    publicJoinPromotion($foreign, ['reward_title' => 'Foreign reward']);

    $this->actingAs($foreign->user)
        ->get('/join/'.$business->public_id.'?business_id='.$foreign->id.'&public_id='.$foreign->public_id)
        ->assertSee('Selected Business')
        ->assertSee('Promoción de ejemplo.')
        ->assertDontSee('Other Business')->assertDontSee('Foreign reward');
});

it('escapes Business copy and excludes persisted Reward and private credentials without effects', function () {
    $business = Business::factory()->create(['name' => '<script>business()</script>']);
    $pass = CustomerPass::factory()->for($business)->create([
        'validation_token_hash' => 'private-validation-hash',
        'manual_code' => '482731',
        'wallet_object_id' => 'issuer.private-object',
    ]);
    publicJoinPromotion($business, [
        'reward_title' => '<script>reward()</script>',
        'reward_description' => '<img src=x onerror=alert(1)>',
    ]);
    Http::preventStrayRequests();
    Queue::fake();
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->get('/join/'.$business->public_id)
        ->assertSee('<script>business()</script>')->assertDontSee('<script>business()</script>', false)
        ->assertDontSee('<script>reward()</script>')
        ->assertDontSee('<img src=x onerror=alert(1)>')
        ->assertDontSee('validation_token')->assertDontSee('manual_code')
        ->assertDontSee('private-validation-hash')->assertDontSee('482731')
        ->assertDontSee('issuer.private-object')->assertDontSee($pass->public_id)
        ->assertDontSee('Google Wallet')->assertDontSee('Tu Pase ya está listo.');

    expect($writes)->toBeEmpty();
    $this->assertDatabaseCount('customer_passes', 1);
    $this->assertDatabaseCount('promotion_participations', 0);
    Queue::assertNothingPushed();
});

it('locks public identity and reloads only Business facts on subsequent renders', function () {
    $business = Business::factory()->create(['name' => 'Original Business']);
    publicJoinPromotion($business);
    $page = Livewire::test('pages::join', ['public_id' => $business->public_id])
        ->assertSee('Un beneficio especial');
    $business->update(['name' => 'Renamed Business']);

    $page->call('$refresh')->assertSee('Renamed Business')->assertDontSee('Original Business')
        ->assertSee('Promoción de ejemplo.')->assertDontSee('Un café de cortesía');

    expect(fn () => $page->set('publicId', Business::factory()->create()->public_id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('uses saved Business color with contrast-safe ink and clearly illustrative identifiers', function (string $color, string $ink) {
    $business = Business::factory()->create(['pass_background_color' => $color]);

    $this->get('/join/'.$business->public_id)
        ->assertSee('background-color: '.$color.'; color: '.$ink, false)
        ->assertSee('3 / 10 puntos')->assertSee('Hoy tus puntos valen x2')
        ->assertDontSee('Un consumo de cortesía')->assertDontSee('Válido hasta 30 SEP')
        ->assertSee('48273')->assertSee('No sirven para validar visitas.');
})->with([
    'dark color' => ['#000000', '#FFFFFF'],
    'light color' => ['#FFFFFF', '#17131F'],
    'saved brand color' => ['#A77BFF', '#17131F'],
    'mid-tone color requiring black ink' => ['#797979', '#000000'],
]);

it('keeps the interactive sheen contrast-safe on a dark Business color', function () {
    $this->blade('<x-interactive-pass-preview business-name="Dark Business" background-color="#666666" />')
        ->assertSee('background-color: #666666; color: #FFFFFF', false)
        ->assertSee('--pass-sheen-rgb: 0 0 0', false);
});

/** Extracts the rendered semantic example, excluding page-specific surrounding content.
 * @param  string  $html  Complete rendered response under test.
 * @return DOMElement The single shared preview article.
 */
function publicJoinExampleCard(string $html): DOMElement
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath = new DOMXPath($document);
    $cards = $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " app-pass-preview ")]');
    expect($cards)->toHaveCount(1);

    return $cards->item(0);
}

/** Creates frozen Promotion terms across a local daylight-saving transition.
 * @param  Business  $business  Business owning the persisted public information.
 * @param  array<string, mixed>  $overrides  Scenario-specific lifecycle or Reward terms.
 * @return Promotion Persisted original UTC window and frozen IANA timezone.
 */
function publicJoinPromotion(Business $business, array $overrides = []): Promotion
{
    $promotion = $business->promotions()->make([
        'reward_title' => 'Un café de cortesía',
        'reward_description' => 'Café americano mediano.',
        'target_points' => 12,
    ]);
    $promotion->forceFill(array_replace([
        'status' => PromotionStatus::Published,
        'starts_at' => '2018-11-03 03:00:00+00',
        'ends_at' => '2018-11-05 02:00:00+00',
        'timezone_snapshot' => 'America/Sao_Paulo',
    ], $overrides))->save();

    return $promotion;
}
