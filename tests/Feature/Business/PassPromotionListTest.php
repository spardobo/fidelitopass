<?php

use App\Actions\Promotions\SavePromotionDraft;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $clock = Mockery::mock(DatabaseClock::class);
    $clock->shouldReceive('captureForBusinessTimezone')->andReturn([
        'instant' => '2026-10-07 12:00:00+00',
        'business_date' => '2026-10-07',
    ]);
    $this->instance(DatabaseClock::class, $clock);
});

it('keeps ended published promotions out of the current draft listing', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);
    $otherOwner = User::factory()->create();
    Business::factory()->for($otherOwner)->create(['pass_background_color' => '#A77BFF']);
    $action = app(SavePromotionDraft::class);
    $ownedDraft = $action->handle($owner, passPromotionInput('Café de cortesía'));
    $foreignDraft = $action->handle($otherOwner, passPromotionInput('Ajeno'));
    $published = createPublishedPassPromotion(
        $business,
        'Ya finalizada',
        '2026-10-05 12:00:00+00',
        '2026-10-07 12:00:00+00',
        'UTC',
    );

    $response = $this->actingAs($owner)
        ->get(route('business.pass'))
        ->assertOk()
        ->assertSee('Café de cortesía')
        ->assertSee('Borrador')
        ->assertSee(__('business.pass.drafts_heading', ['count' => 1]))
        ->assertSee(__('business.pass.new_promotion'))
        ->assertSee(route('business.promotions.edit', $ownedDraft->public_id), false)
        ->assertDontSee(__('business.pass.promotions_prerequisite_heading'))
        ->assertDontSee(__('business.pass.create_first_promotion'))
        ->assertDontSee('Ajeno')
        ->assertDontSee('Ya finalizada');

    expect($response->getContent())
        ->toContain('data-promotion-public-id="'.$ownedDraft->public_id.'"')
        ->not->toContain('data-promotion-public-id="'.$foreignDraft->public_id.'"');
});

it('lists the active Promotion, every scheduled Promotion, and drafts with frozen local dates', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create([
        'timezone' => 'Pacific/Auckland',
        'pass_background_color' => '#A77BFF',
    ]);
    $instant = '2018-11-04 02:00:00+00';
    $active = createPublishedPassPromotion(
        $business,
        'Recompensa activa',
        $instant,
        '2018-11-05 02:00:00+00',
        'America/Sao_Paulo',
    );
    $firstScheduled = createPublishedPassPromotion(
        $business,
        'Primera programada',
        '2018-11-06 02:00:00+00',
        '2018-11-08 02:00:00+00',
        'America/Sao_Paulo',
    );
    $secondScheduled = createPublishedPassPromotion(
        $business,
        'Segunda programada',
        '2018-11-09 02:00:00+00',
        '2018-11-11 02:00:00+00',
        'America/Sao_Paulo',
    );
    $thirdScheduled = createPublishedPassPromotion(
        $business,
        'Tercera programada',
        '2018-11-14 02:00:00+00',
        '2018-11-16 02:00:00+00',
        'America/Sao_Paulo',
    );
    $ended = createPublishedPassPromotion(
        $business,
        'Promoción terminada',
        '2018-11-01 02:00:00+00',
        $instant,
        'America/Sao_Paulo',
    );
    $cancelled = createPublishedPassPromotion(
        $business,
        'Promoción cancelada',
        '2018-11-20 02:00:00+00',
        '2018-11-22 02:00:00+00',
        'America/Sao_Paulo',
        PromotionStatus::Cancelled,
        '2018-11-04 01:00:00+00',
    );
    $otherOwner = User::factory()->create();
    $otherBusiness = Business::factory()->for($otherOwner)->create();
    $foreign = createPublishedPassPromotion(
        $otherBusiness,
        'Promoción de otro negocio',
        '2018-11-06 02:00:00+00',
        '2018-11-08 02:00:00+00',
        'America/Sao_Paulo',
    );
    $draft = app(SavePromotionDraft::class)->handle($owner, array_replace(
        passPromotionInput('Borrador visible'),
        ['local_start_date' => '2026-11-15', 'local_end_date' => '2026-11-21'],
    ));
    $clock = Mockery::mock(DatabaseClock::class);
    $clock->shouldReceive('captureForBusinessTimezone')->once()->with('Pacific/Auckland')->andReturn([
        'instant' => $instant,
        'business_date' => '2018-11-04',
    ]);
    $this->instance(DatabaseClock::class, $clock);

    $component = Livewire::actingAs($owner)->test('pages::business.pass');

    $component->assertSee(__('business.pass.active_promotion_heading'))
        ->assertSee(__('business.pass.scheduled_promotions_heading', ['count' => 3]))
        ->assertSee(__('business.pass.promotion_active_status'))
        ->assertSee(__('business.pass.promotion_scheduled_status'))
        ->assertSee(__('business.pass.promotion_draft_status'))
        ->assertSee('Recompensa activa')
        ->assertSee('Primera programada')
        ->assertSee('Segunda programada')
        ->assertSee('Tercera programada')
        ->assertSee('Borrador visible')
        ->assertDontSee('Promoción terminada')
        ->assertDontSee('Promoción cancelada')
        ->assertDontSee('Promoción de otro negocio')
        ->assertSee(__('business.pass.promotion_draft_period', ['start' => '03/11/2018', 'end' => '04/11/2018']))
        ->assertSee(__('business.pass.promotion_draft_period', ['start' => '06/11/2018', 'end' => '07/11/2018']))
        ->assertDontSee(route('business.promotions.edit', $active->public_id), false)
        ->assertDontSee(route('business.promotions.edit', $firstScheduled->public_id), false)
        ->assertDontSee(route('business.promotions.edit', $secondScheduled->public_id), false)
        ->assertDontSee(route('business.promotions.edit', $thirdScheduled->public_id), false)
        ->assertDontSee(route('business.promotions.edit', $foreign->public_id), false)
        ->assertSee(route('business.promotions.edit', $draft->public_id), false)
        ->assertSeeHtmlInOrder([
            'data-promotion-phase="active"',
            'data-promotion-phase="scheduled"',
            'data-promotion-phase="scheduled"',
            'data-promotion-phase="scheduled"',
            'data-promotion-phase="draft"',
        ]);

    expect($component->html())->toContain('data-promotion-public-id="'.$active->public_id.'"')
        ->toContain('data-promotion-public-id="'.$firstScheduled->public_id.'"')
        ->toContain('data-promotion-public-id="'.$secondScheduled->public_id.'"')
        ->toContain('data-promotion-public-id="'.$thirdScheduled->public_id.'"')
        ->not->toContain('data-promotion-public-id="'.$ended->public_id.'"')
        ->not->toContain('data-promotion-public-id="'.$cancelled->public_id.'"')
        ->not->toContain('data-promotion-public-id="'.$foreign->public_id.'"');
});

it('does not show the empty state when only a scheduled Promotion is listed', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);
    $scheduled = createPublishedPassPromotion(
        $business,
        'Recompensa futura',
        '2026-10-08 12:00:00+00',
        '2026-10-10 12:00:00+00',
        'UTC',
    );

    $this->actingAs($owner)
        ->get(route('business.pass'))
        ->assertOk()
        ->assertSee('Recompensa futura')
        ->assertSee(__('business.pass.promotion_scheduled_status'))
        ->assertDontSee(__('business.pass.promotions_empty_heading'))
        ->assertDontSee(__('business.pass.create_first_promotion'))
        ->assertSee(__('business.pass.new_promotion'))
        ->assertDontSee(route('business.promotions.edit', $scheduled->public_id), false);
});

it('independently paginates all upcoming promotions and drafts in open disclosures', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);
    $scheduledPromotions = collect(range(1, 6))->map(fn (int $number): Promotion => createPublishedPassPromotion(
        $business,
        'Programada '.$number,
        CarbonImmutable::parse('2026-10-08 12:00:00+00')->addDays($number)->toIso8601String(),
        CarbonImmutable::parse('2026-10-10 12:00:00+00')->addDays($number)->toIso8601String(),
        'UTC',
    ));
    $drafts = collect(range(1, 6))->map(fn (int $number): Promotion => app(SavePromotionDraft::class)->handle(
        $owner,
        array_replace(
            passPromotionInput('Borrador '.$number),
            [
                'local_start_date' => CarbonImmutable::parse('2026-11-01')->addDays($number)->toDateString(),
                'local_end_date' => CarbonImmutable::parse('2026-11-07')->addDays($number)->toDateString(),
            ],
        ),
    ));
    $component = Livewire::actingAs($owner)->test('pages::business.pass');
    $initialHtml = $component->html();

    $component->assertSee(__('business.pass.scheduled_promotions_heading', ['count' => 6]))
        ->assertSee(__('business.pass.drafts_heading', ['count' => 6]))
        ->assertSee('Programada 1')
        ->assertSee('Programada 3')
        ->assertDontSee('Programada 4')
        ->assertDontSee('Programada 6')
        ->assertSee('Borrador 1')
        ->assertSee('Borrador 3')
        ->assertDontSee('Borrador 4')
        ->assertDontSee('Borrador 6');

    expect(substr_count($initialHtml, '<details'))->toBe(2)
        ->and(substr_count($initialHtml, 'wire:ignore.self'))->toBe(2)
        ->and(substr_count($initialHtml, ' open'))->toBe(2);

    $component->call('setPage', 2, 'scheduledPage')
        ->assertSee('Programada 4')
        ->assertSee('Programada 6')
        ->assertDontSee('Programada 3')
        ->assertSee('Borrador 1')
        ->assertDontSee('Borrador 4')
        ->assertSee(__('business.pass.scheduled_promotions_heading', ['count' => 6]));

    $component->call('setPage', 2, 'page')
        ->assertSee('Programada 4')
        ->assertSee('Programada 6')
        ->assertSee('Borrador 4')
        ->assertSee('Borrador 6')
        ->assertDontSee('Borrador 3')
        ->assertSee(__('business.pass.drafts_heading', ['count' => 6]));

    expect($component->get('paginators.scheduledPage'))->toBe(2)
        ->and($component->get('paginators.page'))->toBe(2)
        ->and($scheduledPromotions)->toHaveCount(6)
        ->and($drafts)->toHaveCount(6);
});

it('keeps the promotions empty state and does not render placeholder draft rows', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);

    $this->actingAs($owner)
        ->get(route('business.pass'))
        ->assertOk()
        ->assertSee(__('business.pass.promotions_empty'))
        ->assertSee(__('business.pass.promotions_prerequisite_heading'))
        ->assertSee(__('business.pass.create_first_promotion'))
        ->assertDontSee(__('business.pass.drafts_heading'))
        ->assertDontSee(__('business.pass.new_promotion'))
        ->assertDontSee('data-promotion-public-id', false)
        ->assertSee(route('business.promotions.create'), false);
});

it('consumes the saved-draft flash once for the Pase toast', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);
    $flashKey = 'business.promotion.draft_saved';

    session()->flash($flashKey, true);

    Livewire::actingAs($owner)
        ->test('pages::business.pass')
        ->assertSet('draftSavedNoticePending', true)
        ->assertNotDispatched('toast-show');

    expect(session()->has($flashKey))->toBeFalse();

    Livewire::actingAs($owner)
        ->test('pages::business.pass')
        ->assertSet('draftSavedNoticePending', false)
        ->assertNotDispatched('toast-show');
});

it('preserves the appearance prerequisite in the empty unprepared state', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->get(route('business.pass'))
        ->assertOk()
        ->assertSee(__('business.pass.promotions_empty_heading'))
        ->assertSee(__('business.pass.promotions_prerequisite'))
        ->assertSee(__('business.pass.promotions_empty'))
        ->assertDontSee(__('business.pass.drafts_heading'))
        ->assertDontSee(__('business.pass.new_promotion'))
        ->assertDontSee(__('business.pass.create_first_promotion'))
        ->assertDontSee('data-promotion-public-id', false);
});

it('keeps owned drafts visible without offering creation before the pass is prepared', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();
    $draft = app(SavePromotionDraft::class)->handle($owner, passPromotionInput('Café de cortesía'));

    $this->actingAs($owner)
        ->get(route('business.pass'))
        ->assertOk()
        ->assertSee(__('business.pass.drafts_heading', ['count' => 1]))
        ->assertSee('Café de cortesía')
        ->assertSee(__('business.pass.promotions_prerequisite'))
        ->assertDontSee(__('business.pass.new_promotion'))
        ->assertDontSee(__('business.pass.promotions_empty_heading'))
        ->assertDontSee(__('business.pass.create_first_promotion'))
        ->assertSee(route('business.promotions.edit', $draft->public_id), false)
        ->assertDontSee(route('business.promotions.create'), false);
});

it('orders drafts by earliest local start date before paginating, then uses deterministic ties', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);
    $action = app(SavePromotionDraft::class);
    $cases = [
        ['days' => 5, 'updated_at' => '2026-11-01 12:00:00+00'],
        ['days' => 1, 'updated_at' => '2026-10-10 12:00:00+00'],
        ['days' => 3, 'updated_at' => '2026-10-13 12:00:00+00'],
        ['days' => 2, 'updated_at' => '2026-10-11 12:00:00+00'],
        ['days' => 4, 'updated_at' => '2026-10-12 12:00:00+00'],
        ['days' => 1, 'updated_at' => '2026-10-15 12:00:00+00'],
        ['days' => 0, 'updated_at' => '2026-10-01 12:00:00+00'],
        ['days' => 3, 'updated_at' => '2026-10-13 12:00:00+00'],
        ['days' => 6, 'updated_at' => '2026-11-02 12:00:00+00'],
        ['days' => 2, 'updated_at' => '2026-10-15 12:00:00+00'],
        ['days' => 0, 'updated_at' => '2026-10-01 12:00:00+00'],
        ['days' => 5, 'updated_at' => '2026-10-30 12:00:00+00'],
    ];
    $drafts = collect($cases)->map(function (array $case, int $index) use ($action, $owner): Promotion {
        $start = CarbonImmutable::parse('2026-10-07')->addDays($case['days']);
        $promotion = $action->handle($owner, array_merge(
            passPromotionInput('Borrador '.($index + 1)),
            [
                'local_start_date' => $start->toDateString(),
                'local_end_date' => $start->addDays(7)->toDateString(),
            ],
        ));
        $promotion->forceFill(['updated_at' => $case['updated_at']])->saveQuietly();

        return $promotion;
    });

    $component = Livewire::actingAs($owner)->test('pages::business.pass');
    $expectedOrder = collect([11, 7, 6, 2, 10, 4, 8, 3, 5, 1, 12, 9])
        ->map(fn (int $number): string => $drafts[$number - 1]->public_id)
        ->values();

    $firstPage = $expectedOrder->take(3)
        ->map(fn (string $publicId): string => 'data-promotion-public-id="'.$publicId.'"')
        ->all();
    $secondPage = $expectedOrder->slice(3, 3)
        ->map(fn (string $publicId): string => 'data-promotion-public-id="'.$publicId.'"')
        ->all();
    $thirdPage = $expectedOrder->slice(6, 3)
        ->map(fn (string $publicId): string => 'data-promotion-public-id="'.$publicId.'"')
        ->all();
    $lastPage = $expectedOrder->slice(9)
        ->map(fn (string $publicId): string => 'data-promotion-public-id="'.$publicId.'"')
        ->all();

    $component->assertSeeHtmlInOrder($firstPage)
        ->assertDontSee('data-promotion-public-id="'.$expectedOrder[3].'"', false)
        ->assertDontSee('data-promotion-public-id="'.$expectedOrder[11].'"', false);

    $component->call('gotoPage', 2)
        ->assertSeeHtmlInOrder($secondPage)
        ->assertDontSee('data-promotion-public-id="'.$expectedOrder[6].'"', false)
        ->assertDontSee('data-promotion-public-id="'.$expectedOrder[11].'"', false);

    $component->call('gotoPage', 3)
        ->assertSeeHtmlInOrder($thirdPage)
        ->assertDontSee('data-promotion-public-id="'.$expectedOrder[9].'"', false)
        ->assertDontSee('data-promotion-public-id="'.$expectedOrder[11].'"', false);

    $component->call('gotoPage', 4)
        ->assertSeeHtmlInOrder($lastPage);

    expect($business->promotions()->whereNull('local_start_date')->exists())->toBeFalse();
});

/**
 * Builds the fixed default draft input with the requested reward title.
 *
 * @param  string  $title  Reward title used to identify the generated Promotion.
 * @return array{local_start_date: string, local_end_date: string, target_points: int, reward_title: string, reward_description: string, extra_points: list<never>} Draft fields with the requested title and no extra-point windows.
 */
function passPromotionInput(string $title): array
{
    return [
        'local_start_date' => '2026-11-01',
        'local_end_date' => '2026-11-07',
        'target_points' => 8,
        'reward_title' => $title,
        'reward_description' => '',
        'extra_points' => [],
    ];
}

/**
 * Persists a valid published-state fixture with an immutable UTC window and timezone snapshot.
 *
 * @param  Business  $business  Business that owns the generated Promotion.
 * @param  string  $title  Reward title used to identify the generated Promotion.
 * @param  string  $startsAt  Inclusive UTC start instant.
 * @param  string  $endsAt  Exclusive UTC end instant.
 * @param  string  $timezoneSnapshot  IANA timezone frozen at publication.
 * @param  PromotionStatus  $status  Published lifecycle state for the fixture.
 * @param  string|null  $cancelledAt  Optional cancellation instant required for cancelled fixtures.
 * @return Promotion Persisted Promotion with a database-valid publication state.
 */
function createPublishedPassPromotion(
    Business $business,
    string $title,
    string $startsAt,
    string $endsAt,
    string $timezoneSnapshot,
    PromotionStatus $status = PromotionStatus::Published,
    ?string $cancelledAt = null,
): Promotion {
    $startsAt = CarbonImmutable::parse($startsAt);
    $endsAt = CarbonImmutable::parse($endsAt);
    $promotion = $business->promotions()->make([
        'local_start_date' => null,
        'local_end_date' => null,
        'target_points' => 8,
        'reward_title' => $title,
        'reward_description' => '',
    ]);
    $promotion->forceFill([
        'status' => $status,
        'timezone_snapshot' => $timezoneSnapshot,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'cancelled_at' => $cancelledAt === null ? null : CarbonImmutable::parse($cancelledAt),
    ])->save();

    return $promotion;
}
