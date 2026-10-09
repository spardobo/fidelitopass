<?php

use App\Actions\Promotions\CancelPromotion;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use App\Support\DatabaseClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->cancellationInstant = '2026-01-02 12:00:00+00';
    $clock = Mockery::mock(DatabaseClock::class);
    $clock->shouldReceive('captureForBusinessTimezone')->andReturnUsing(fn () => [
        'instant' => $this->cancellationInstant,
        'business_date' => '2026-01-02',
    ]);
    $this->instance(DatabaseClock::class, $clock);
});

it('cancels a confirmed eligible Promotion while retaining frozen detail and Pase inputs', function (string $start) {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $promotion = passCancellationPromotion($business, $start);
    $before = $promotion->getRawOriginal();
    $rules = $promotion->extraPoints()->get()->toArray();

    Livewire::actingAs($owner)->test('pages::business.pass')
        ->set('backgroundColor', '#E53935')
        ->call('setPage', 2)
        ->call('setPage', 2, 'scheduledPage')
        ->call('setPage', 2, 'historyPage')
        ->call('showPromotionDetail', $promotion->public_id)
        ->call('requestPromotionCancellation')
        ->assertSet('confirmingPromotionCancellation', true)
        ->assertSee(__('business.pass.confirm_cancel_promotion'))
        ->assertSee('data-flux-callout', false)
        ->assertSee(__('business.pass.cancel_promotion_warning'))
        ->call('confirmPromotionCancellation')
        ->assertHasNoErrors()
        ->assertSet('confirmingPromotionCancellation', false)
        ->assertSet('selectedPromotionId', $promotion->public_id)
        ->assertSet('backgroundColor', '#E53935')
        ->assertSet('paginators.page', 2)
        ->assertSet('paginators.scheduledPage', 2)
        ->assertSet('paginators.historyPage', 1)
        ->assertSee('data-promotion-detail-phase="cancelled"', false)
        ->assertSee('data-promotion-public-id="'.$promotion->public_id.'"', false)
        ->assertSee('data-promotion-phase="cancelled"', false)
        ->assertSee(__('business.pass.history_promotions_heading', ['count' => 1]))
        ->assertDontSee(__('business.pass.cancel_promotion'));

    $fresh = $promotion->fresh();
    expect($fresh->status)->toBe(PromotionStatus::Cancelled)
        ->and($fresh->cancelled_at->toIso8601String())
        ->toBe('2026-01-02T12:00:00+00:00')
        ->and(Arr::except($fresh->getRawOriginal(), ['status', 'cancelled_at', 'updated_at']))
        ->toBe(Arr::except($before, ['status', 'cancelled_at', 'updated_at']))
        ->and($fresh->extraPoints()
            ->get()
            ->toArray())
        ->toBe($rules);
})->with(['active' => '2026-01-01 00:00:00+00', 'scheduled' => '2026-01-03 00:00:00+00']);

it('requires server confirmation and closes detail without changing the Promotion', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $promotion = passCancellationPromotion($business);
    $before = $promotion->getRawOriginal();

    Livewire::actingAs($owner)->test('pages::business.pass')
        ->call('showPromotionDetail', $promotion->public_id)
        ->call('confirmPromotionCancellation')
        ->assertHasErrors('promotionCancellation')
        ->assertSee(__('business.pass.cancel_confirmation_required'))
        ->call('requestPromotionCancellation')
        ->assertHasNoErrors()
        ->call('dismissPromotionDetail')
        ->assertSet('confirmingPromotionCancellation', false)
        ->assertSet('selectedPromotionId', null)
        ->assertHasNoErrors()
        ->call('showPromotionDetail', $promotion->public_id)
        ->call('confirmPromotionCancellation')
        ->assertHasErrors('promotionCancellation')
        ->call('requestPromotionCancellation')
        ->call('dismissPromotionDetail')
        ->assertSet('confirmingPromotionCancellation', false)
        ->assertSet('selectedPromotionId', null)
        ->assertHasNoErrors();

    expect($promotion->fresh()->getRawOriginal())->toBe($before);
});

it('changes the cancellation action from primary to destructive only at final confirmation', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $promotion = passCancellationPromotion($business);
    $component = Livewire::actingAs($owner)->test('pages::business.pass')
        ->call('showPromotionDetail', $promotion->public_id);
    $document = new DOMDocument;
    @$document->loadHTML(mb_convert_encoding($component->html(), 'HTML-ENTITIES', 'UTF-8'));
    $requestButton = (new DOMXPath($document))->query('//*[@id="cancel-promotion"]')->item(0);

    expect($requestButton->getAttribute('class'))->toContain('app-button-primary', 'bg-[var(--color-accent)]')
        ->not->toContain('bg-red-500');
    expect(trim($requestButton->textContent))->toBe(__('business.pass.cancel_promotion'));

    $component->call('requestPromotionCancellation');
    @$document->loadHTML(mb_convert_encoding($component->html(), 'HTML-ENTITIES', 'UTF-8'));
    $confirmButton = (new DOMXPath($document))->query('//*[@data-promotion-detail-phase]//footer//button[@*[name()="wire:click"]="confirmPromotionCancellation"]')->item(0);

    expect($confirmButton->getAttribute('class'))->toContain('app-button', 'bg-red-500')
        ->not->toContain('app-button-primary');
    expect(trim($confirmButton->textContent))->toBe(__('business.pass.confirm_cancel_promotion'));
});

it('keeps return to Pase as the only secondary action before and during cancellation confirmation', function (bool $confirming) {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $promotion = passCancellationPromotion($business);
    $component = Livewire::actingAs($owner)->test('pages::business.pass')
        ->call('showPromotionDetail', $promotion->public_id);

    if ($confirming) {
        $component->call('requestPromotionCancellation');
    }

    $document = new DOMDocument;
    @$document->loadHTML(mb_convert_encoding($component->html(), 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($document);
    $closeButtons = $xpath->query('//*[@data-promotion-detail-phase]//footer//ui-close//button');

    expect($closeButtons->length)->toBe(1);
    expect(trim($closeButtons->item(0)->textContent))->toBe(__('business.pass.close_promotion_detail'));
    expect($xpath->query('//*[@data-promotion-detail-phase]//footer//button')->length)->toBe(2);
    $component->assertDontSee('Conservar promoción');
})->with(['detail' => false, 'confirmation' => true]);

it('rejects client hydration of the cancellation confirmation', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();
    $component = Livewire::actingAs($owner)->test('pages::business.pass');

    expect(fn () => $component->set('confirmingPromotionCancellation', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('keeps the first cancellation instant and gives safe feedback to a stale confirmation', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $promotion = passCancellationPromotion($business);
    $staleTab = Livewire::actingAs($owner)->test('pages::business.pass')
        ->call('showPromotionDetail', $promotion->public_id)
        ->call('requestPromotionCancellation');
    app(CancelPromotion::class)->handle($owner, $promotion);
    $before = $promotion->fresh()->getRawOriginal();
    $this->cancellationInstant = '2026-01-02 13:00:00+00';

    $staleTab->call('confirmPromotionCancellation')
        ->assertHasErrors('promotionCancellation')
        ->assertSee(__('business.promotion.already_cancelled'))
        ->assertSet('confirmingPromotionCancellation', false)
        ->assertSee('data-promotion-detail-phase="cancelled"', false)
        ->assertDontSee(__('business.pass.cancel_promotion'));

    expect($promotion->fresh()->getRawOriginal())->toBe($before);
});

it('rejects cancellation when the original end arrives after opening confirmation', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $promotion = passCancellationPromotion($business);
    $before = $promotion->getRawOriginal();
    $component = Livewire::actingAs($owner)->test('pages::business.pass')
        ->call('showPromotionDetail', $promotion->public_id)
        ->call('requestPromotionCancellation');
    $this->cancellationInstant = '2026-01-05 00:00:00+00';

    $component->call('confirmPromotionCancellation')
        ->assertHasErrors('promotionCancellation')
        ->assertSee(__('business.promotion.cancel_ended'))
        ->assertSet('confirmingPromotionCancellation', false)
        ->assertSee('data-promotion-detail-phase="ended"', false)
        ->assertDontSee(__('business.pass.cancel_promotion'))
        ->call('requestPromotionCancellation')
        ->assertSet('confirmingPromotionCancellation', false);

    expect($promotion->fresh()->getRawOriginal())->toBe($before);
});

it('rechecks ownership on confirmation after the authenticated actor changes', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $promotion = passCancellationPromotion($business);
    $before = $promotion->getRawOriginal();
    $component = Livewire::actingAs($owner)->test('pages::business.pass')
        ->call('showPromotionDetail', $promotion->public_id)
        ->call('requestPromotionCancellation');
    $otherOwner = User::factory()->create();
    Business::factory()->for($otherOwner)->create();
    $this->actingAs($otherOwner);

    $component->call('confirmPromotionCancellation')->assertNotFound();

    expect($promotion->fresh()->getRawOriginal())->toBe($before);
});

it('reports unexpected cancellation failures without exposing details or losing the selection', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $promotion = passCancellationPromotion($business);
    $before = $promotion->getRawOriginal();
    $failure = new RuntimeException('SQL private/path credential');
    $this->mock(CancelPromotion::class)->shouldReceive('handle')->once()->andThrow($failure);
    Exceptions::fake();

    Livewire::actingAs($owner)->test('pages::business.pass')
        ->call('showPromotionDetail', $promotion->public_id)
        ->call('requestPromotionCancellation')
        ->call('confirmPromotionCancellation')
        ->assertHasErrors('promotionCancellation')
        ->assertSee(__('business.pass.cancel_error_unexpected'))
        ->assertDontSee($failure->getMessage())
        ->assertSet('confirmingPromotionCancellation', false)
        ->assertSet('selectedPromotionId', $promotion->public_id)
        ->assertSee(__('business.pass.cancel_promotion'));

    Exceptions::assertReported(RuntimeException::class);
    expect($promotion->fresh()->getRawOriginal())->toBe($before);
});

/**
 * Creates frozen published terms and one multiplier for the cancellation journey.
 *
 * @param  Business  $business  Business owning the fixture.
 * @param  string  $start  Inclusive UTC start controlling the displayed phase.
 * @return Promotion Persisted eligible Promotion with original terms and rules.
 */
function passCancellationPromotion(Business $business, string $start = '2026-01-01 00:00:00+00'): Promotion
{
    $promotion = $business->promotions()->make([
        'reward_title' => 'Original reward',
        'reward_description' => 'Original terms',
        'target_points' => 8,
    ]);
    $promotion->forceFill([
        'status' => PromotionStatus::Published,
        'starts_at' => $start,
        'ends_at' => '2026-01-05 00:00:00+00',
        'timezone_snapshot' => 'UTC',
    ])->save();
    $promotion->extraPoints()->create(['weekday' => 1, 'start_time' => null, 'end_time' => null, 'multiplier' => 3]);

    return $promotion->fresh();
}
