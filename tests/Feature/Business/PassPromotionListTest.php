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

it('shows only the current business drafts with public edit links', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['pass_background_color' => '#A77BFF']);
    $otherOwner = User::factory()->create();
    Business::factory()->for($otherOwner)->create(['pass_background_color' => '#A77BFF']);
    $action = app(SavePromotionDraft::class);
    $ownedDraft = $action->handle($owner, passPromotionInput('Café de cortesía'));
    $foreignDraft = $action->handle($otherOwner, passPromotionInput('Ajeno'));
    $published = $action->handle($owner, passPromotionInput('Ya publicada'));
    $published->forceFill(['status' => PromotionStatus::Published])->save();

    $response = $this->actingAs($owner)
        ->get(route('business.pass'))
        ->assertOk()
        ->assertSee('Café de cortesía')
        ->assertSee('Borrador')
        ->assertSee(__('business.pass.drafts_heading'))
        ->assertSee(__('business.pass.new_promotion'))
        ->assertSee(route('business.promotions.edit', $ownedDraft->public_id), false)
        ->assertDontSee(__('business.pass.promotions_prerequisite_heading'))
        ->assertDontSee(__('business.pass.create_first_promotion'))
        ->assertDontSee('Ajeno')
        ->assertDontSee('Ya publicada');

    expect($response->getContent())
        ->toContain('data-promotion-public-id="'.$ownedDraft->public_id.'"')
        ->not->toContain('data-promotion-public-id="'.$foreignDraft->public_id.'"');
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
        ->assertSee(__('business.pass.drafts_heading'))
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

    $firstPage = $expectedOrder->take(10)
        ->map(fn (string $publicId): string => 'data-promotion-public-id="'.$publicId.'"')
        ->all();
    $lastPage = $expectedOrder->slice(10)
        ->map(fn (string $publicId): string => 'data-promotion-public-id="'.$publicId.'"')
        ->all();

    $component->assertSeeHtmlInOrder($firstPage)
        ->assertDontSee('data-promotion-public-id="'.$expectedOrder[10].'"', false)
        ->assertDontSee('data-promotion-public-id="'.$expectedOrder[11].'"', false);

    $component->call('gotoPage', 2)
        ->assertSeeHtmlInOrder($lastPage);

    expect($business->promotions()->whereNull('local_start_date')->exists())->toBeFalse();
});

/**
 * Build the fixed default draft input with the requested reward title.
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
