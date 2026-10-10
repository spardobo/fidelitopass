<?php

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use App\Support\DatabaseClock;
use App\Support\PromotionDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->detailInstant = '2018-11-03 03:00:00+00';
    $clock = Mockery::mock(DatabaseClock::class);
    $clock->shouldReceive('captureForBusinessTimezone')->andReturnUsing(fn () => [
        'instant' => $this->detailInstant,
        'business_date' => '2018-11-03',
    ]);
    $this->instance(DatabaseClock::class, $clock);
});

it('opens frozen owned terms with the current database phase', function (string $instant, PromotionStatus $status, string $phase, string $page) {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'America/Sao_Paulo']);
    $promotion = detailPromotion($business, [
        'status' => $status,
        'cancelled_at' => $status === PromotionStatus::Cancelled ? '2018-11-03 04:00:00+00' : null,
    ]);
    $business->update(['timezone' => 'Pacific/Auckland']);
    $this->detailInstant = $instant;
    $this->travelTo('2040-01-01');
    $dates = app(PromotionDetail::class)->read($owner, $promotion->public_id);
    expect($dates['start_date'])->toBe('2018-11-03')
        ->and($dates['end_date'])->toBe('2018-11-04');

    $component = Livewire::actingAs($owner)->test($page)
        ->call('showPromotionDetail', $promotion->public_id)
        ->assertDispatched('modal-show', name: 'promotion-detail')
        ->assertSee('data-promotion-detail-phase="'.$phase.'"', false)
        ->assertSee('Premio original')
        ->assertSee('Condiciones originales')
        ->assertSee('12 puntos')
        ->assertSee('datetime="2018-11-03"', false)
        ->assertSee('datetime="2018-11-04"', false)
        ->assertSee('11/03/2018')
        ->assertSee('11/04/2018')
        ->assertSee(__('business.promotion.review_no_extra_points'))
        ->assertDontSee('Confirmar publicación');

    if ($page === 'pages::business.pass' && in_array($phase, ['scheduled', 'active'], true)) {
        $component->assertSee('Cancelar promoción');
    } else {
        $component->assertDontSee('Cancelar promoción');
    }

    $document = new DOMDocument;
    @$document->loadHTML(mb_convert_encoding($component->html(), 'HTML-ENTITIES', 'UTF-8'));
    $badge = (new DOMXPath($document))->query('//*[@data-promotion-detail-phase]//*[@data-flux-badge]')->item(0);
    $palette = match ($phase) {
        'active' => 'green',
        'scheduled' => 'blue',
        'cancelled' => 'red',
        default => 'zinc',
    };
    expect($badge->getAttribute('class'))->toContain('bg-'.$palette.'-400/')->not->toContain('bg-'.$palette.'-500');
})->with([
    'scheduled before inclusive start' => ['2018-11-03 02:59:59+00', PromotionStatus::Published, 'scheduled'],
    'active at inclusive start' => ['2018-11-03 03:00:00+00', PromotionStatus::Published, 'active'],
    'ended at exclusive end' => ['2018-11-05 02:00:00+00', PromotionStatus::Published, 'ended'],
    'cancelled before original end' => ['2018-11-03 05:00:00+00', PromotionStatus::Cancelled, 'cancelled'],
])->with(['pages::business.pass', 'pages::business.summary']);

it('renders all persisted rules and escaped Reward text without writing or reusing editor state', function (string $page) {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $promotion = detailPromotion($business, [
        'reward_title' => '<script>Reward</script>',
        'reward_description' => '<strong>Original terms</strong>',
    ]);
    $promotion->extraPoints()->createMany([
        ['weekday' => 1, 'start_time' => null, 'end_time' => null, 'multiplier' => 2],
        ['weekday' => 3, 'start_time' => '10:00', 'end_time' => '13:00', 'multiplier' => 3],
        ['weekday' => 5, 'start_time' => '18:00', 'end_time' => '20:00', 'multiplier' => 5],
    ]);
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    Livewire::actingAs($owner)->test($page)
        ->call('showPromotionDetail', $promotion->public_id)
        ->assertSee('<script>Reward</script>')
        ->assertSee('<strong>Original terms</strong>')
        ->assertDontSee('<script>Reward</script>', false)
        ->assertDontSee('<strong>Original terms</strong>', false)
        ->assertSee('3 configuraciones')
        ->assertSee('Lunes · Todo el día')
        ->assertSee('Miércoles · 10:00–13:00')
        ->assertSee('Viernes · 18:00–20:00')
        ->assertSee('×2')->assertSee('×3')->assertSee('×5')
        ->call('dismissPromotionDetail')
        ->assertSet('selectedPromotionId', null)
        ->assertDontSee('3 configuraciones');

    expect($writes)->toBeEmpty();
})->with(['pages::business.pass', 'pages::business.summary']);

it('exposes detail triggers for active and scheduled rows and retains Pase state on dismissal', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $active = detailPromotion($business);
    $scheduled = detailPromotion($business, ['starts_at' => '2018-11-06 02:00:00+00', 'ends_at' => '2018-11-08 02:00:00+00']);

    Livewire::actingAs($owner)->test('pages::business.pass')
        ->assertSee('wire:click="showPromotionDetail(\''.$active->public_id.'\')"', false)
        ->assertSee('wire:click="showPromotionDetail(\''.$scheduled->public_id.'\')"', false)
        ->set('backgroundColor', '#E53935')
        ->call('setPage', 2)
        ->call('showPromotionDetail', $scheduled->public_id)
        ->call('dismissPromotionDetail')
        ->assertSet('selectedPromotionId', null)
        ->assertSet('backgroundColor', '#E53935')
        ->assertSet('paginators.page', 2);
});

it('keeps historical rows concise while preserving full original terms in detail', function (PromotionStatus $status) {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $promotion = detailPromotion($business, [
        'status' => $status,
        'cancelled_at' => $status === PromotionStatus::Cancelled ? '2018-11-03 04:00:00+00' : null,
    ]);
    $this->detailInstant = '2018-11-05 02:00:00+00';

    $component = Livewire::actingAs($owner)->test('pages::business.summary');
    $document = new DOMDocument;
    @$document->loadHTML(mb_convert_encoding($component->html(), 'HTML-ENTITIES', 'UTF-8'));
    $row = (new DOMXPath($document))->query('//section[@aria-labelledby="promotion-history-heading"]//li[@data-promotion-public-id="'.$promotion->public_id.'"]')->item(0);

    expect($row)->not->toBeNull();
    expect($row->textContent)->toContain(
        'Premio original', '11/03/2018 – 11/04/2018', __('business.pass.view_promotion_detail'),
        $status === PromotionStatus::Cancelled ? __('business.pass.promotion_cancelled_status') : __('business.pass.promotion_ended_status'),
    )->not->toContain('Condiciones originales', '12 puntos');

    $component->call('showPromotionDetail', $promotion->public_id)
        ->assertSee('Condiciones originales')
        ->assertSee('12 puntos');
})->with(['ended' => PromotionStatus::Published, 'cancelled' => PromotionStatus::Cancelled]);

it('returns 404 for foreign draft missing or malformed detail identifiers', function (string $selection, string $page) {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $foreignBusiness = Business::factory()->create();
    $foreign = detailPromotion($foreignBusiness, ['reward_title' => 'Private foreign reward']);
    $draft = $business->promotions()->create([
        'reward_title' => 'Private draft reward',
        'reward_description' => '',
        'target_points' => 8,
        'local_start_date' => '2018-11-03',
        'local_end_date' => '2018-11-04',
    ]);
    $id = match ($selection) {
        'foreign' => $foreign->public_id,
        'draft' => $draft->public_id,
        'missing' => '00000000-0000-4000-8000-000000000000',
        'array' => ['public_id' => $foreign->public_id],
        default => "' OR 1=1 --",
    };

    Livewire::actingAs($owner)->test($page)
        ->call('showPromotionDetail', $id)
        ->assertNotFound()
        ->assertNotDispatched('modal-show');
})->with(['foreign', 'draft', 'missing', 'malformed', 'array'])->with(['pages::business.pass', 'pages::business.summary']);

it('rejects client hydration of the selected detail identity', function (string $page) {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $promotion = detailPromotion($business);
    $component = Livewire::actingAs($owner)->test($page);

    expect(fn () => $component->set('selectedPromotionId', $promotion->public_id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['pages::business.pass', 'pages::business.summary']);

it('reloads persisted detail and phase after a later request instead of trusting previous public state', function (string $page) {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $promotion = detailPromotion($business);
    $component = Livewire::actingAs($owner)->test($page)
        ->call('showPromotionDetail', $promotion->public_id)
        ->assertSee('data-promotion-detail-phase="active"', false);
    $this->detailInstant = '2018-11-05 02:00:00+00';

    $component->call('$refresh')
        ->assertSee('data-promotion-detail-phase="ended"', false);

    $otherOwner = User::factory()->create();
    Business::factory()->for($otherOwner)->create();
    $this->actingAs($otherOwner);
    $component->call('$refresh')->assertNotFound();
})->with(['pages::business.pass', 'pages::business.summary']);

it('keeps Summary detail read only with a contextual native close action', function (string $origin) {
    $business = Business::factory()->create();
    $promotion = detailPromotion($business);

    Livewire::actingAs($business->user)->test('pages::business.summary')
        ->call('showPromotionDetail', $promotion->public_id, $origin)
        ->assertSet('promotionDetailOrigin', $origin)
        ->assertSee('Volver al resumen')
        ->assertDontSee('Volver al pase')
        ->assertDontSee('Cancelar promoción')
        ->call('dismissPromotionDetail')
        ->assertSet('selectedPromotionId', null)
        ->assertDontSee('data-promotion-detail-phase', false);

    expect($promotion->fresh()->status)->toBe(PromotionStatus::Published);
})->with(['primary', 'upcoming', 'history']);

it('rejects unrecognized Summary detail origins and locks focus identity against hydration', function () {
    $business = Business::factory()->create();
    $promotion = detailPromotion($business);
    Livewire::actingAs($business->user)->test('pages::business.summary')
        ->call('showPromotionDetail', $promotion->public_id, 'foreign-region')->assertNotFound();

    $component = Livewire::actingAs($business->user)->test('pages::business.summary');
    expect(fn () => $component->set('promotionDetailOrigin', 'history'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('centers shared summary fact icons and labels on their main value rows', function () {
    $response = $this->blade(
        '<x-promotion-summary reward-title="Saved reward" target-points="12" start-date="2018-11-03" end-date="2018-11-04" :extra-points="$rules" />',
        ['rules' => [['weekday' => 1, 'start_time' => null, 'end_time' => null, 'multiplier' => 2]]],
    );
    $document = new DOMDocument;
    @$document->loadHTML(mb_convert_encoding((string) $response, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($document);

    expect($xpath->query('//dl/div[contains(@class,"items-center")]')->length)->toBe(2);
    expect($xpath->query('//dl/div/span[@aria-hidden="true" and contains(@class,"col-start-1") and contains(@class,"row-start-1") and not(contains(@class,"row-span-2"))]')->length)->toBe(2);
    expect(trim($xpath->query('//dl/div[2]/dd[1]')->item(0)->textContent))->toBe('11/03/2018 – 11/04/2018');
    expect(trim($xpath->query('//dl/div[2]/dd[2]')->item(0)?->textContent ?? ''))->toBe(__('business.promotion.inclusive_end_date'));
    expect($xpath->query('//details[@data-promotion-review-extra-rules]/summary[contains(@class,"items-center")]/span[@aria-hidden="true" and contains(@class,"row-start-1") and not(contains(@class,"row-span-2"))]')->length)->toBe(1);
});

/**
 * Creates frozen original terms across a snapshot-local daylight-saving transition.
 *
 * @param  Business  $business  Owner of the persisted Promotion.
 * @param  array<string, mixed>  $overrides  Scenario-specific published attributes.
 * @return Promotion Persisted Promotion retaining original UTC bounds and timezone.
 */
function detailPromotion(Business $business, array $overrides = []): Promotion
{
    $promotion = $business->promotions()->make([
        'reward_title' => 'Premio original',
        'reward_description' => 'Condiciones originales',
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
