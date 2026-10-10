<?php

use App\Actions\Promotions\PublishPromotion;
use App\Actions\Promotions\SavePromotionDraft;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders supplied summary terms without a Livewire editor', function () {
    $this->blade(
        '<x-promotion-summary :reward-title="$title" :reward-description="$description" target-points="12" start-date="2026-12-02" end-date="2026-12-09" :extra-points="$rules" />',
        [
            'title' => '<script>Reward</script>',
            'description' => '<strong>Original terms</strong>',
            'rules' => [
                reviewPromotionWindow(1, null, null, 2),
                reviewPromotionWindow(3, '10:00', '13:00', 3),
                reviewPromotionWindow(5, '18:00', '20:00', 5),
            ],
        ],
    )
        ->assertSee('<script>Reward</script>')
        ->assertSee('<strong>Original terms</strong>')
        ->assertDontSee('<script>Reward</script>', false)
        ->assertDontSee('<strong>Original terms</strong>', false)
        ->assertSee('12 puntos')
        ->assertSee('12/02/2026')
        ->assertSee('12/09/2026')
        ->assertSee('3 configuraciones')
        ->assertSee('Lunes · Todo el día')
        ->assertSee('Miércoles · 10:00–13:00')
        ->assertSee('Viernes · 18:00–20:00')
        ->assertSee('×2')
        ->assertSee('×3')
        ->assertSee('×5')
        ->assertDontSee('Confirmar publicación')
        ->assertDontSee('Después de publicar');
});

it('renders fixed padded calendar dates before JavaScript without truncating the year', function () {
    $this->blade('<x-regional-date date="0099-01-02" end-date="2026-12-09" />')
        ->assertSee('datetime="0099-01-02"', false)
        ->assertSee('datetime="2026-12-09"', false)
        ->assertSee('01/02/0099')
        ->assertSee('12/09/2026');
});

it('keeps malformed editor dates available for server validation without parsing preview input', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);

    Livewire::actingAs($owner)->test('pages::business.promotion')
        ->set('rewardTitle', 'Café de cortesía')
        ->set('targetPoints', '8')
        ->set('localEndDate', '2026-11-07')
        ->set('localStartDate', 'not-a-date')
        ->call('reviewPublication')
        ->assertHasErrors('localStartDate')
        ->assertSet('localStartDate', 'not-a-date')
        ->assertSet('localEndDate', '2026-11-07');

    expect($business->promotions()->count())->toBe(0);
});

it('opens a compact publication modal over the unchanged editor without saving', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create([
        'timezone' => 'America/La_Paz',
        'pass_background_color' => '#A77BFF',
    ]);
    $input = reviewPromotionInput([
        'extra_points' => [reviewPromotionWindow(2, '09:00', '12:00', 3)],
    ]);

    $component = Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', $input['reward_title'])
        ->set('rewardDescription', $input['reward_description'])
        ->set('targetPoints', (string) $input['target_points'])
        ->set('localStartDate', $input['local_start_date'])
        ->set('localEndDate', $input['local_end_date'])
        ->set('extraPoints', $input['extra_points'])
        ->call('reviewPublication')
        ->assertDispatched('modal-show', name: 'promotion-publication-review')
        ->assertHasNoErrors()
        ->assertSee('PUBLICAR PROMOCIÓN')
        ->assertSee('¿Listo para publicar?')
        ->assertSee('Publicar promoción')
        ->assertDontSee('Resumen de la promoción')
        ->assertSee('Nueva promoción')
        ->assertSee('Café de cortesía')
        ->assertSee('Café americano mediano')
        ->assertSee('8 puntos')
        ->assertSee('datetime="2026-11-01"', false)
        ->assertSee('11/01/2026')
        ->assertSee('11/07/2026')
        ->assertDontSee('Zona horaria del negocio')
        ->assertDontSee('America/La_Paz')
        ->assertDontSee('1 punto por visita')
        ->assertDontSee('La promoción comenzará el 11/01/2026.')
        ->assertSee('1 punto')
        ->assertSee('Martes')
        ->assertSee('3 puntos')
        ->assertSee('Después de publicar')
        ->assertSee('Una vez publicada, no podrás modificar esta promoción.')
        ->assertSee('1 configuración')
        ->assertDontSee('Lista para publicar')
        ->assertSee('Confirmar publicación')
        ->assertDontSee('2026-11-01 04:00:00 UTC')
        ->assertDontSee('2026-11-08 04:00:00 UTC');

    expect(substr_count($component->html(), 'Publicar promoción'))->toBe(1);

    $document = new DOMDocument;
    @$document->loadHTML('<meta charset="UTF-8">'.$component->html());
    $xpath = new DOMXPath($document);
    $summaryCard = $xpath->query('//*[@data-test="promotion-review-summary"]')->item(0);
    $summaryFactCount = $xpath->query('//*[@data-test="promotion-review-summary"]//dt')->length;
    $extraSummary = $xpath->query('//*[@data-test="promotion-review-extra-rules"]/summary')->item(0);
    $extraIcon = $xpath->query('//*[@data-test="promotion-review-extra-icon"]')->item(0);
    $extraRuleList = $xpath->query('//*[@data-test="promotion-review-extra-rules"]//ul')->item(0);
    $summaryLabels = $xpath->query('//*[@data-test="promotion-review-summary"]//dt');
    $scrollBody = $xpath->query('//*[@data-test="promotion-review-scroll-body"]')->item(0);
    $actionFooter = $xpath->query('//*[@data-test="promotion-review-actions"]')->item(0);
    $footerInsideScrollBody = $xpath->query('//*[@data-test="promotion-review-scroll-body"]//*[@data-test="promotion-review-actions"]')->item(0);
    $extraRules = $xpath->query('//*[@data-test="promotion-review-extra-rules"]')->item(0);

    expect($summaryCard)->toBeInstanceOf(DOMElement::class)
        ->and($summaryFactCount)->toBe(2)
        ->and($scrollBody)->not->toBeNull()
        ->and($actionFooter)->not->toBeNull()
        ->and($footerInsideScrollBody)->toBeNull()
        ->and($extraRules)->toBeInstanceOf(DOMElement::class);

    if ($summaryCard instanceof DOMElement) {
        expect($summaryCard->textContent)->not->toContain('Visita habitual');
    }

    expect($extraSummary)->toBeInstanceOf(DOMElement::class);

    if ($extraSummary instanceof DOMElement) {
        expect($extraSummary->textContent)
            ->toContain('Puntos extra')
            ->toContain('1 configuración');
    }

    expect($extraIcon)->toBeInstanceOf(DOMElement::class);

    if ($extraIcon instanceof DOMElement) {
        expect($extraIcon->getAttribute('class'))->toContain('self-stretch');
    }

    expect($extraRuleList)->toBeInstanceOf(DOMElement::class);

    if ($extraRuleList instanceof DOMElement) {
        expect($extraRuleList->getAttribute('class'))->not->toContain('sm:ms-9');
    }

    foreach ($summaryLabels as $summaryLabel) {
        expect($summaryLabel->getAttribute('class'))->toContain('sm:self-center');
    }

    if ($scrollBody instanceof DOMElement) {
        expect($scrollBody->hasAttribute('autofocus'))->toBeTrue();
    }

    if ($extraRules instanceof DOMElement) {
        expect($extraRules->hasAttribute('open'))->toBeFalse();
    }

    expect($component->get('publicationReview')['start_date'])->toBe('2026-11-01')
        ->and($component->get('publicationReview')['end_date'])->toBe('2026-11-07')
        ->and($component->get('publicationReview')['starts_at'])->toBe('2026-11-01 04:00:00 UTC')
        ->and($component->get('publicationReview')['ends_at'])->toBe('2026-11-08 04:00:00 UTC');

    expect($owner->business->promotions()->count())->toBe(0);

    $component->call('dismissPublicationReview')
        ->assertHasNoErrors()
        ->assertDontSee('Resumen de la promoción')
        ->assertSee('Nueva promoción')
        ->assertSet('rewardTitle', 'Café de cortesía')
        ->assertSet('rewardDescription', 'Café americano mediano')
        ->assertSet('targetPoints', '8')
        ->assertSet('localStartDate', '2026-11-01')
        ->assertSet('localEndDate', '2026-11-07')
        ->assertSet('extraPoints', $input['extra_points']);

    expect($owner->business->promotions()->count())->toBe(0);
});

it('describes an empty extra-points review explicitly without an empty disclosure', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);
    $input = reviewPromotionInput();

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', $input['reward_title'])
        ->set('rewardDescription', $input['reward_description'])
        ->set('targetPoints', (string) $input['target_points'])
        ->set('localStartDate', $input['local_start_date'])
        ->set('localEndDate', $input['local_end_date'])
        ->set('extraPoints', $input['extra_points'])
        ->call('reviewPublication')
        ->assertDispatched('modal-show', name: 'promotion-publication-review')
        ->assertHasNoErrors()
        ->assertSee('Sin puntos extra')
        ->assertDontSee('data-test="promotion-review-extra-rules"', false);

    expect($owner->business->promotions()->count())->toBe(0);
});

it('requires a server-owned review before confirmation', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', 'Café de cortesía')
        ->set('targetPoints', '8')
        ->set('localStartDate', '2026-11-01')
        ->set('localEndDate', '2026-11-07')
        ->call('confirmPublication')
        ->assertHasErrors('promotion')
        ->assertSee('Revisa la publicación antes de confirmarla.');

    expect($owner->business->promotions()->count())->toBe(0);
});

it('publishes the whole reviewed aggregate and redirects with a one-time success notice', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create([
        'timezone' => 'America/La_Paz',
        'pass_background_color' => '#A77BFF',
    ]);
    $input = reviewPromotionInput([
        'extra_points' => [reviewPromotionWindow(2, '09:00', '12:00', 3)],
    ]);

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', $input['reward_title'])
        ->set('rewardDescription', $input['reward_description'])
        ->set('targetPoints', (string) $input['target_points'])
        ->set('localStartDate', $input['local_start_date'])
        ->set('localEndDate', $input['local_end_date'])
        ->set('extraPoints', $input['extra_points'])
        ->call('reviewPublication')
        ->call('confirmPublication')
        ->assertHasNoErrors()
        ->assertRedirect(route('business.pass'));

    $promotion = $owner->business->promotions()->with('extraPoints')->sole();
    expect($promotion->status)->toBe(PromotionStatus::Published)
        ->and($promotion->reward_title)->toBe('Café de cortesía')
        ->and($promotion->reward_description)->toBe('Café americano mediano')
        ->and($promotion->target_points)->toBe(8)
        ->and($promotion->timezone_snapshot)->toBe('America/La_Paz')
        ->and($promotion->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-01 04:00:00')
        ->and($promotion->ends_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-08 04:00:00')
        ->and($promotion->extraPoints)->toHaveCount(1)
        ->and($promotion->extraPoints->sole()->multiplier)->toBe(3);
    expect(session('business.promotion.publication_notice'))->toBe('published');

    $this->withSession(['business.promotion.publication_notice' => 'published']);
    Livewire::actingAs($owner)
        ->test('pages::business.pass')
        ->assertSee('Promoción publicada.');

    Livewire::actingAs($owner)
        ->test('pages::business.pass')
        ->assertSet('publicationNotice', '')
        ->assertDontSee('Promoción publicada.');
});

it('refreshes the review timezone and requires a second explicit confirmation', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create([
        'timezone' => 'America/La_Paz',
        'pass_background_color' => '#A77BFF',
    ]);

    $component = Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', 'Café de cortesía')
        ->set('targetPoints', '8')
        ->set('localStartDate', '2026-11-01')
        ->set('localEndDate', '2026-11-07')
        ->call('reviewPublication')
        ->assertDontSee('La zona horaria cambió.')
        ->assertDontSee('data-test="promotion-review-extra-rules"', false)
        ->assertDontSee('2026-11-01 04:00:00 UTC');

    expect($component->get('publicationReview')['starts_at'])->toBe('2026-11-01 04:00:00 UTC')
        ->and($component->get('publicationReview')['ends_at'])->toBe('2026-11-08 04:00:00 UTC');

    $business->update(['timezone' => 'America/Los_Angeles']);

    $component->call('confirmPublication')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertSet('reviewingPublication', false)
        ->assertSet('reviewedTimezone', '')
        ->assertDispatched('modal-close', name: 'promotion-publication-review')
        ->assertDispatched('toast-show', slots: ['text' => __('business.promotion.timezone_review_refreshed')], dataset: ['variant' => 'danger'])
        ->assertDontSee('Zona horaria del negocio')
        ->assertDontSee('America/Los_Angeles')
        ->assertDontSee('2026-11-01 07:00:00 UTC')
        ->assertDontSee('2026-11-08 08:00:00 UTC');

    $component->call('reviewPublication')
        ->assertDispatched('modal-show', name: 'promotion-publication-review')
        ->assertSet('reviewedTimezone', 'America/Los_Angeles')
        ->assertHasNoErrors()
        ->assertSee('11/01/2026')
        ->assertSee('11/07/2026');

    expect($component->get('publicationReview')['starts_at'])->toBe('2026-11-01 07:00:00 UTC')
        ->and($component->get('publicationReview')['ends_at'])->toBe('2026-11-08 08:00:00 UTC')
        ->and($component->get('publicationReview')['timezone'])->toBe('America/Los_Angeles');

    expect($business->promotions()->count())->toBe(0);

    $component->call('confirmPublication')
        ->assertHasNoErrors()
        ->assertRedirect(route('business.pass'));

    $promotion = $business->promotions()->sole();
    expect($promotion->timezone_snapshot)->toBe('America/Los_Angeles')
        ->and($promotion->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-01 07:00:00')
        ->and($promotion->ends_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-08 08:00:00');
});

it('keeps validation errors in the review and does not persist an incomplete aggregate', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', '')
        ->set('targetPoints', '8')
        ->set('localStartDate', '2026-11-01')
        ->set('localEndDate', '2026-11-07')
        ->call('reviewPublication')
        ->assertHasErrors('rewardTitle')
        ->assertNotDispatched('modal-show')
        ->assertSee('¿Qué recompensa recibirá tu cliente?');

    expect($owner->business->promotions()->count())->toBe(0);
});

it('keeps an overlapping publication rejection in the editor without persisting it', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create([
        'timezone' => 'America/La_Paz',
        'pass_background_color' => '#A77BFF',
    ]);
    app(PublishPromotion::class)->handleSubmitted($owner, reviewPromotionInput([
        'local_start_date' => '2026-11-01',
        'local_end_date' => '2026-11-07',
        'reward_title' => 'Promoción existente',
    ]), 'America/La_Paz');

    $component = Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', 'Nueva recompensa')
        ->set('targetPoints', '8')
        ->set('localStartDate', '2026-11-03')
        ->set('localEndDate', '2026-11-09')
        ->call('reviewPublication')
        ->call('confirmPublication')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertSet('reviewingPublication', false)
        ->assertSet('rewardTitle', 'Nueva recompensa')
        ->assertSet('localStartDate', '2026-11-03')
        ->assertSet('localEndDate', '2026-11-09')
        ->assertDispatched('modal-close', name: 'promotion-publication-review')
        ->assertDispatched('toast-show', slots: ['text' => __('business.promotion.publication_window_overlaps')], dataset: ['variant' => 'danger']);

    expect($owner->business->promotions()->count())->toBe(1)
        ->and(session()->has('business.promotion.publication_notice'))->toBeFalse();
});

it('keeps confirmation field errors on the editor while closing the invalid review', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', 'Recompensa conservada')
        ->set('targetPoints', '8')
        ->set('localStartDate', '2026-11-01')
        ->set('localEndDate', '2026-11-07')
        ->call('reviewPublication')
        ->set('targetPoints', '0')
        ->call('confirmPublication')
        ->assertHasErrors('targetPoints')
        ->assertNoRedirect()
        ->assertSet('reviewingPublication', false)
        ->assertSet('targetPoints', '0')
        ->assertSet('rewardTitle', 'Recompensa conservada')
        ->assertDispatched('modal-close', name: 'promotion-publication-review')
        ->assertDispatched('toast-show', slots: ['text' => __('business.promotion.validation_notice')], dataset: ['variant' => 'danger']);

    expect($owner->business->promotions()->count())->toBe(0);
});

it('reports unexpected publication failures and returns safe feedback without redirecting', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);
    $exception = new RuntimeException('SELECT secret_value FROM internal_table');
    $publisher = Mockery::mock(PublishPromotion::class);
    $publisher->shouldReceive('handleSubmitted')->once()->andThrow($exception);
    app()->instance(PublishPromotion::class, $publisher);
    Exceptions::fake();

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', 'Recompensa conservada')
        ->set('targetPoints', '8')
        ->set('localStartDate', '2026-11-01')
        ->set('localEndDate', '2026-11-07')
        ->call('reviewPublication')
        ->call('confirmPublication')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertSet('reviewingPublication', false)
        ->assertSet('rewardTitle', 'Recompensa conservada')
        ->assertDispatched('modal-close', name: 'promotion-publication-review')
        ->assertDispatched('toast-show', slots: ['text' => __('business.promotion.publication_error_unexpected')], dataset: ['variant' => 'danger'])
        ->assertDontSee('SELECT secret_value FROM internal_table');

    Exceptions::assertReported(RuntimeException::class);
    expect($owner->business->promotions()->count())->toBe(0);
});

it('publishes an edited draft in place instead of creating a second promotion', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create([
        'timezone' => 'America/La_Paz',
        'pass_background_color' => '#A77BFF',
    ]);
    $draft = app(SavePromotionDraft::class)->handle($owner, reviewPromotionInput([
        'local_start_date' => '2026-11-15',
        'local_end_date' => '2026-11-21',
        'reward_title' => 'Borrador anterior',
    ]));

    Livewire::actingAs($owner)
        ->test('pages::business.promotion', ['promotion' => $draft])
        ->set('rewardTitle', 'Recompensa revisada')
        ->call('reviewPublication')
        ->call('confirmPublication')
        ->assertHasNoErrors()
        ->assertRedirect(route('business.pass'));

    expect($owner->business->promotions()->count())->toBe(1)
        ->and($draft->refresh()->status)->toBe(PromotionStatus::Published)
        ->and($draft->reward_title)->toBe('Recompensa revisada');
});

it('authorizes the owner before opening the review for an existing draft', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);
    Business::factory()->for($otherOwner)->create(['pass_background_color' => '#A77BFF']);
    $draft = app(SavePromotionDraft::class)->handle($owner, reviewPromotionInput());

    $this->actingAs($otherOwner)
        ->get(route('business.promotions.edit', ['promotion' => $draft->public_id]))
        ->assertNotFound();
});

it('shows the PostgreSQL-compatible exclusive UTC boundary across a skipped local midnight', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create([
        'timezone' => 'America/Sao_Paulo',
        'pass_background_color' => '#A77BFF',
    ]);

    $component = Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', 'Café de cortesía')
        ->set('targetPoints', '8')
        ->set('localStartDate', '2018-11-03')
        ->set('localEndDate', '2018-11-04')
        ->call('reviewPublication')
        ->assertHasNoErrors()
        ->assertSee('11/03/2018')
        ->assertSee('11/04/2018')
        ->assertDontSee('2018-11-03 03:00:00 UTC')
        ->assertDontSee('2018-11-05 02:00:00 UTC');

    expect($component->get('publicationReview')['starts_at'])->toBe('2018-11-03 03:00:00 UTC')
        ->and($component->get('publicationReview')['ends_at'])->toBe('2018-11-05 02:00:00 UTC');
});

it('rejects client attempts to alter the reviewed timezone or confirmation state', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);

    expect(fn () => Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('reviewedTimezone', 'UTC'))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('reviewingPublication', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('keeps a stale second-tab publication failure in the editor without changing the published aggregate', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create([
        'timezone' => 'America/La_Paz',
        'pass_background_color' => '#A77BFF',
    ]);
    $draft = app(SavePromotionDraft::class)->handle($owner, reviewPromotionInput([
        'local_start_date' => '2026-11-15',
        'local_end_date' => '2026-11-21',
        'reward_title' => 'Borrador anterior',
    ]));

    $staleTab = Livewire::actingAs($owner)
        ->test('pages::business.promotion', ['promotion' => $draft])
        ->set('rewardTitle', 'Versión de la pestaña desactualizada')
        ->call('reviewPublication');

    Livewire::actingAs($owner)
        ->test('pages::business.promotion', ['promotion' => $draft])
        ->set('rewardTitle', 'Versión ya publicada')
        ->call('reviewPublication')
        ->call('confirmPublication')
        ->assertRedirect(route('business.pass'));

    $published = $draft->refresh()->load('extraPoints')->toArray();

    $staleTab->call('confirmPublication')
        ->assertNoRedirect()
        ->assertHasNoErrors()
        ->assertSet('reviewingPublication', false)
        ->assertSet('rewardTitle', 'Versión de la pestaña desactualizada')
        ->assertDispatched('modal-close', name: 'promotion-publication-review')
        ->assertDispatched('toast-show', slots: ['text' => __('business.promotion.only_drafts_can_be_published')], dataset: ['variant' => 'danger']);

    expect($draft->refresh()->load('extraPoints')->toArray())->toBe($published)
        ->and(session()->has('business.promotion.publication_notice'))->toBeFalse();
});

it('allows a stale publication review to close without treating dismissal as a draft edit', function () {
    freezePromotionReviewClock();
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create([
        'timezone' => 'America/La_Paz',
        'pass_background_color' => '#A77BFF',
    ]);
    $draft = app(SavePromotionDraft::class)->handle($owner, reviewPromotionInput());

    $staleTab = Livewire::actingAs($owner)
        ->test('pages::business.promotion', ['promotion' => $draft])
        ->assertStatus(200)
        ->assertSet('promotionPublicId', $draft->public_id)
        ->call('reviewPublication')
        ->assertStatus(200)
        ->assertSet('promotionPublicId', $draft->public_id);

    app(PublishPromotion::class)->handleSubmitted($owner, reviewPromotionInput(), 'America/La_Paz', $draft);
    $publishedSnapshot = $draft->refresh()->attributesToArray();

    $staleTab->call('dismissPublicationReview')
        ->assertStatus(200)
        ->assertSet('promotionPublicId', $draft->public_id)
        ->assertHasNoErrors()
        ->assertSet('reviewingPublication', false)
        ->assertDontSee('Resumen de la promoción');

    expect($draft->refresh()->attributesToArray())->toBe($publishedSnapshot)
        ->and($draft->status)->toBe(PromotionStatus::Published);
});

/**
 * Builds valid Promotion input for review/publication tests.
 *
 * @param  array<string, mixed>  $overrides  Submitted fields that replace the valid defaults.
 * @return array<string, mixed> Complete valid Promotion terms and multiplier rules.
 */
function reviewPromotionInput(array $overrides = []): array
{
    return array_replace([
        'local_start_date' => '2026-11-01',
        'local_end_date' => '2026-11-07',
        'target_points' => 8,
        'reward_title' => 'Café de cortesía',
        'reward_description' => 'Café americano mediano',
        'extra_points' => [],
    ], $overrides);
}

/**
 * Builds one valid multiplier rule for review/publication tests.
 *
 * @param  int  $weekday  ISO weekday number for the recurring rule.
 * @param  string|null  $startTime  Inclusive local start time, or null for all day.
 * @param  string|null  $endTime  Exclusive local end time, or null for all day.
 * @param  int  $multiplier  Total points awarded per qualifying visit.
 * @return array{weekday: int, start_time: string|null, end_time: string|null, multiplier: int} Valid multiplier window.
 */
function reviewPromotionWindow(int $weekday, ?string $startTime, ?string $endTime, int $multiplier): array
{
    return [
        'weekday' => $weekday,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'multiplier' => $multiplier,
    ];
}

/**
 * Freezes the component and publication-action clock to a stable future test date.
 */
function freezePromotionReviewClock(): void
{
    app()->instance(DatabaseClock::class, new class extends DatabaseClock
    {
        /**
         * Returns the same operation instant and its date in the requested Business timezone.
         *
         * @param  string  $timezone  IANA timezone used for the deterministic business date.
         * @return array{instant: string, business_date: string} Fixed instant and derived local date.
         */
        public function captureForBusinessTimezone(string $timezone): array
        {
            $instant = CarbonImmutable::parse('2026-10-08 12:00:00 UTC');

            return [
                'instant' => $instant->toDateTimeString(),
                'business_date' => $instant->setTimezone($timezone)->toDateString(),
            ];
        }
    });
}
