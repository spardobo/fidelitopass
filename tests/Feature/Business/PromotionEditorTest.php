<?php

use App\Actions\Promotions\SavePromotionDraft;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use App\Support\DatabaseClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

it('requires authentication and email verification to open the promotion editor', function () {
    $this->get(route('business.promotions.create'))
        ->assertRedirect(route('login'));

    $owner = User::factory()->unverified()->create();
    Business::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->get(route('business.promotions.create'))
        ->assertRedirect(route('verification.notice'));
});

it('renders the create editor with draft date guidance and unavailable publication disabled', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);

    $this->actingAs($owner)
        ->get(route('business.promotions.create'))
        ->assertOk()
        ->assertSee('Nueva promoción')
        ->assertDontSee('America/La_Paz')
        ->assertSee('La fecha de fin incluye ese día completo. La recompensa también vence al terminar la promoción.')
        ->assertSee('required', false)
        ->assertSee('aria-hidden="true" class="text-app-danger-ink me-1">*</span> ', false)
        ->assertSee('Revisar publicación')
        ->assertSee('disabled', false)
        ->assertDontSee('La publicación no está disponible en esta versión.');
});

it('keeps the summary vigencia unconfigured until both dates are present', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('localStartDate', '2026-11-01')
        ->assertSee('Fechas por definir')
        ->set('localEndDate', '2026-11-07')
        ->assertSee('2026-11-01 – 2026-11-07')
        ->set('localStartDate', '')
        ->assertSee('Fechas por definir');
});

it('uses Spanish field attributes for editor and builder validation messages', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('targetPoints', '12')
        ->set('localStartDate', '2026-11-01')
        ->set('localEndDate', '2026-11-07')
        ->call('save')
        ->assertHasErrors('rewardTitle')
        ->assertSee('El campo recompensa es obligatorio.')
        ->assertDontSee('El campo reward title es obligatorio.')
        ->set('draftWeekday', '8')
        ->call('addExtraPoint')
        ->assertHasErrors('draftWeekday')
        ->assertSee('El campo día tiene que estar entre 1 y 7.')
        ->assertDontSee('El campo draft weekday tiene que estar entre 1 y 7.')
        ->set('draftWeekday', '')
        ->set('extraPoints', [promotionEditorWindow(8, '09:00', '10:00', 2)])
        ->call('save')
        ->assertHasErrors('extraPoints.0.weekday')
        ->assertSee('El campo día de puntos extra tiene que estar entre 1 y 7.')
        ->assertDontSee('El campo extra points.0.weekday tiene que estar entre 1 y 7.');
});

it('uses a translated wildcard attribute for malformed extra-point rows', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    try {
        app(SavePromotionDraft::class)->handle($owner, promotionEditorDraftInput([
            'extra_points' => ['not-a-rule'],
        ]));

        $this->fail('A non-array extra-point row was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('extra_points.0')
            ->and($exception->errors()['extra_points.0'][0])
            ->toBe('El campo regla de puntos extra debe ser un conjunto.');
    }
});

it('loads a draft for edit through its public identifier and shows its saved weekly rules', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $promotion = app(SavePromotionDraft::class)->handle($owner, promotionEditorDraftInput([
        'extra_points' => [promotionEditorWindow(1, '09:00', '10:00', 3)],
    ]));

    $this->actingAs($owner)
        ->get(route('business.promotions.edit', $promotion->public_id))
        ->assertOk()
        ->assertSee('Editar borrador')
        ->assertDontSee('America/La_Paz')
        ->assertSee('09:00')
        ->assertSee('×3');
});

it('hides another business promotion behind the not found response', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();
    $otherOwner = User::factory()->create();
    Business::factory()->for($otherOwner)->create();
    $promotion = app(SavePromotionDraft::class)->handle($otherOwner, promotionEditorDraftInput());

    $this->actingAs($owner)
        ->get(route('business.promotions.edit', $promotion->public_id))
        ->assertNotFound();
});

it('does not expose an editor for a promotion that has left draft status', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();
    $promotion = app(SavePromotionDraft::class)->handle($owner, promotionEditorDraftInput());
    $promotion->forceFill([
        'local_start_date' => null,
        'local_end_date' => null,
        'status' => PromotionStatus::Published,
        'timezone_snapshot' => 'America/La_Paz',
        'starts_at' => '2026-11-01 04:00:00+00',
        'ends_at' => '2026-11-08 04:00:00+00',
    ])->save();

    $this->actingAs($owner)
        ->get(route('business.promotions.edit', $promotion->public_id))
        ->assertNotFound();
});

it('saves a complete draft and its rules through the editor', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', 'Café y medialuna')
        ->set('rewardDescription', 'Una opción de desayuno.')
        ->set('targetPoints', '12')
        ->set('localStartDate', '2026-11-01')
        ->set('localEndDate', '2026-11-07')
        ->set('extraPoints', [promotionEditorWindow(1, '09:00', '12:00', 2)])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('business.pass'));

    $promotion = Promotion::query()->with('extraPoints')->sole();

    expect($promotion->status)->toBe(PromotionStatus::Draft)
        ->and($promotion->reward_title)->toBe('Café y medialuna')
        ->and($promotion->target_points)->toBe(12)
        ->and($promotion->extraPoints)->toHaveCount(1)
        ->and($promotion->extraPoints->first()->multiplier)->toBe(2);
});

it('keeps the editor bound to its owner-scoped draft when the public identifier is tampered with', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $otherOwner = User::factory()->create();
    $otherBusiness = Business::factory()->for($otherOwner)->create();
    $promotion = app(SavePromotionDraft::class)->handle($owner, promotionEditorDraftInput());
    $otherPromotion = app(SavePromotionDraft::class)->handle($otherOwner, promotionEditorDraftInput());

    expect(fn () => Livewire::actingAs($owner)
        ->test('pages::business.promotion', ['promotion' => $promotion])
        ->set('promotionPublicId', $otherPromotion->public_id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    Livewire::actingAs($owner)
        ->test('pages::business.promotion', ['promotion' => $promotion])
        ->set('rewardTitle', 'Nueva recompensa')
        ->call('save')
        ->assertHasNoErrors();

    expect($promotion->fresh()->business_id)->toBe($business->id)
        ->and($promotion->fresh()->reward_title)->toBe('Nueva recompensa')
        ->and(Promotion::query()->where('business_id', $otherBusiness->id)->value('public_id'))->toBe($otherPromotion->public_id)
        ->and(Promotion::query()->count())->toBe(2);
});

it('rejects an incomplete extra-point row without saving or silently dropping it', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', 'Café y medialuna')
        ->set('targetPoints', '12')
        ->set('localStartDate', '2026-11-01')
        ->set('localEndDate', '2026-11-07')
        ->set('draftWeekday', '1')
        ->set('draftMode', 'timed')
        ->set('draftStartTime', '09:00')
        ->call('save')
        ->assertHasErrors('extraPoints')
        ->assertDispatched('toast-show')
        ->assertSee('Completa la regla antes de guardar.');

    $this->assertDatabaseCount('promotions', 0);
    $this->assertDatabaseCount('promotion_multiplier_windows', 0);
});

it('keeps field errors and adds a toast when draft validation fails', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('localStartDate', '2026-11-01')
        ->set('localEndDate', '2026-11-07')
        ->set('targetPoints', '12')
        ->call('save')
        ->assertHasErrors('rewardTitle')
        ->assertDispatched('toast-show');

    $this->assertDatabaseCount('promotions', 0);
});

it('keeps Action validation errors on component properties across Livewire updates', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    $component = Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->call('save')
        ->assertDispatched('toast-show')
        ->set('targetPoints', '12')
        ->assertHasErrors(['rewardTitle', 'localStartDate', 'localEndDate'])
        ->assertSee('El campo recompensa es obligatorio.')
        ->assertSee('El campo fecha de inicio es obligatorio.')
        ->assertSee('El campo fecha de fin es obligatorio.');

    $component->set('rewardTitle', 'Café de cortesía')
        ->set('localStartDate', '2026-11-01')
        ->set('localEndDate', '2026-11-07')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseCount('promotions', 1);
});

it('retains mapped extra-point child errors and clears them after a corrected submission', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    $component = Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', 'Café de cortesía')
        ->set('targetPoints', '12')
        ->set('localStartDate', '2026-11-01')
        ->set('localEndDate', '2026-11-07')
        ->set('extraPoints', [promotionEditorWindow(8, '09:00', '10:00', 2)])
        ->call('save')
        ->assertDispatched('toast-show')
        ->set('targetPoints', '13')
        ->assertHasErrors('extraPoints.0.weekday')
        ->assertSee('El campo día de puntos extra tiene que estar entre 1 y 7.');

    $component->set('extraPoints', [promotionEditorWindow(1, '09:00', '10:00', 2)])
        ->call('save')
        ->assertHasNoErrors();

    expect($component->get('extraPoints'))->toHaveCount(1);
});

it('shows separate localized required errors for each missing timed-rule field', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('draftWeekday', '1')
        ->set('draftMode', 'timed')
        ->set('draftStartTime', '09:00')
        ->set('draftEndTime', '')
        ->call('addExtraPoint')
        ->assertHasErrors('draftEndTime')
        ->assertSee('El campo hora de fin es obligatorio.')
        ->assertDontSee('Completa la hora de inicio y fin.')
        ->set('draftStartTime', '')
        ->set('draftEndTime', '10:00')
        ->call('addExtraPoint')
        ->assertHasErrors('draftStartTime')
        ->assertSee('El campo hora de inicio es obligatorio.')
        ->assertDontSee('Completa la hora de inicio y fin.');
});

it('shows both localized required errors when both timed-rule fields are missing', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('draftWeekday', '1')
        ->set('draftMode', 'timed')
        ->set('draftStartTime', '')
        ->set('draftEndTime', '')
        ->call('addExtraPoint')
        ->assertHasErrors(['draftStartTime', 'draftEndTime'])
        ->assertSee('El campo hora de inicio es obligatorio.')
        ->assertSee('El campo hora de fin es obligatorio.')
        ->assertDontSee('Completa la hora de inicio y fin.');
});

it('renders a Business-local database date hint and rejects past starts in the editor', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $clock = Mockery::mock(DatabaseClock::class);
    $clock->shouldReceive('captureForBusinessTimezone')->twice()
        ->with('America/La_Paz')
        ->andReturn(['instant' => '2026-10-07 12:00:00+00', 'business_date' => '2026-10-07']);
    $this->instance(DatabaseClock::class, $clock);

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->assertSee('min="2026-10-07"', false)
        ->set('rewardTitle', 'Café de cortesía')
        ->set('targetPoints', '8')
        ->set('localStartDate', '2026-10-06')
        ->set('localEndDate', '2026-10-07')
        ->call('save')
        ->assertHasErrors('localStartDate')
        ->assertSee('La fecha de inicio debe ser hoy o posterior en la zona horaria del negocio.')
        ->assertDispatched('toast-show');

    $this->assertDatabaseCount('promotions', 0);
});

it('keeps stale drafts editable and saves only after their owner corrects the dates', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $promotion = $business->promotions()->create([
        'local_start_date' => '2026-10-06',
        'local_end_date' => '2026-10-14',
        'target_points' => 8,
        'reward_title' => 'Café de cortesía',
    ]);
    $originalUpdatedAt = $promotion->updated_at;

    $editor = Livewire::actingAs($owner)
        ->test('pages::business.promotion', ['promotion' => $promotion])
        ->assertSet('localStartDate', '2026-10-06')
        ->assertSee('min="2026-10-07"', false)
        ->set('rewardTitle', 'Café para dos')
        ->call('save')
        ->assertHasErrors('localStartDate')
        ->assertSee('La fecha de inicio debe ser hoy o posterior en la zona horaria del negocio.')
        ->assertDispatched('toast-show');

    expect($promotion->fresh()->local_start_date->toDateString())->toBe('2026-10-06')
        ->and($promotion->fresh()->reward_title)->toBe('Café de cortesía')
        ->and($promotion->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue();

    $editor->set('localStartDate', '2026-10-07')
        ->set('rewardTitle', 'Café para dos')
        ->call('save')
        ->assertHasNoErrors();

    expect($promotion->fresh()->local_start_date->toDateString())->toBe('2026-10-07')
        ->and($promotion->fresh()->reward_title)->toBe('Café para dos');
});

it('rejects conflicting rule additions without changing accepted or pending rules', function (array $accepted, array $pending) {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    $component = Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('extraPoints', [$accepted])
        ->set('draftWeekday', '1')
        ->set('draftMode', $pending['mode'])
        ->set('draftMultiplier', '3')
        ->set('draftStartTime', $pending['start'] ?? '')
        ->set('draftEndTime', $pending['end'] ?? '')
        ->call('addExtraPoint')
        ->assertHasErrors('extraPoints')
        ->assertDispatched('toast-show');

    expect($component->get('extraPoints'))->toBe([$accepted])
        ->and($component->get('draftWeekday'))->toBe('1')
        ->and($component->get('draftMode'))->toBe($pending['mode'])
        ->and($component->get('draftStartTime'))->toBe($pending['start'] ?? '')
        ->and($component->get('draftEndTime'))->toBe($pending['end'] ?? '');

    $component->call('discardRuleDraft')->assertHasNoErrors();
    expect($component->get('extraPoints'))->toBe([$accepted]);
})->with([
    'whole day first blocks a time' => [promotionEditorWindow(1, null, null, 2), ['mode' => 'timed', 'start' => '14:00', 'end' => '15:00']],
    'time first blocks whole day' => [promotionEditorWindow(1, '14:00', '15:00', 2), ['mode' => 'all_day']],
    'overlap blocks another time' => [promotionEditorWindow(1, '14:00', '15:30', 2), ['mode' => 'timed', 'start' => '15:00', 'end' => '16:00']],
    'duplicate all day blocks another all day' => [promotionEditorWindow(1, null, null, 2), ['mode' => 'all_day']],
]);

it('accepts touching rule additions and resets only the pending row', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();
    $accepted = promotionEditorWindow(1, '14:00', '15:00', 2);

    $component = Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('extraPoints', [$accepted])
        ->set('draftWeekday', '1')
        ->set('draftMode', 'timed')
        ->set('draftStartTime', '15:00')
        ->set('draftEndTime', '16:00')
        ->call('addExtraPoint')
        ->assertHasNoErrors();

    expect($component->get('extraPoints'))->toBe([
        $accepted,
        promotionEditorWindow(1, '15:00', '16:00', 2),
    ])->and($component->get('draftWeekday'))->toBe('');
});

it('orders accepted weekly rules by weekday and start time before removing a displayed row', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    $component = Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('draftWeekday', '3')
        ->set('draftMode', 'timed')
        ->set('draftStartTime', '14:00')
        ->set('draftEndTime', '15:00')
        ->set('draftMultiplier', '5')
        ->call('addExtraPoint')
        ->set('draftWeekday', '1')
        ->set('draftMode', 'timed')
        ->set('draftStartTime', '16:00')
        ->set('draftEndTime', '17:00')
        ->set('draftMultiplier', '3')
        ->call('addExtraPoint')
        ->set('draftWeekday', '1')
        ->set('draftMode', 'timed')
        ->set('draftStartTime', '09:00')
        ->set('draftEndTime', '10:00')
        ->set('draftMultiplier', '2')
        ->call('addExtraPoint')
        ->assertHasNoErrors();

    $orderedRules = [
        promotionEditorWindow(1, '09:00', '10:00', 2),
        promotionEditorWindow(1, '16:00', '17:00', 3),
        promotionEditorWindow(3, '14:00', '15:00', 5),
    ];

    expect($component->get('extraPoints'))->toBe($orderedRules);

    $component->call('removeExtraPoint', 1);

    expect($component->get('extraPoints'))->toBe([
        $orderedRules[0],
        $orderedRules[2],
    ]);
});

it('does not expose pending rule copy and prevents saving an unadded rule', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();
    $accepted = promotionEditorWindow(2, '09:00', '10:00', 3);

    $component = Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', 'Café de cortesía')
        ->set('rewardDescription', 'Un detalle de bienvenida.')
        ->set('targetPoints', '12')
        ->set('localStartDate', '2026-11-01')
        ->set('localEndDate', '2026-11-30')
        ->assertSee('Café de cortesía')
        ->assertSee('Un detalle de bienvenida.')
        ->assertSee('2026-11-01 – 2026-11-30')
        ->set('extraPoints', [$accepted])
        ->set('draftWeekday', '1')
        ->set('draftMode', 'timed')
        ->set('draftStartTime', '14:00')
        ->set('draftEndTime', '15:00')
        ->assertDontSee('Lunes · 14:00–15:00 · ×2')
        ->assertDontSee('Entrada sin añadir')
        ->assertSee('1 configuración')
        ->assertDontSee('2 configuraciones')
        ->call('save')
        ->assertHasErrors('extraPoints')
        ->assertSee('Completa la regla antes de guardar.');

    expect($component->get('extraPoints'))->toBe([$accepted])
        ->and($component->get('draftWeekday'))->toBe('1')
        ->and($component->get('draftStartTime'))->toBe('14:00');
});

it('cancels a new draft without persisting pending input', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::business.promotion')
        ->set('rewardTitle', 'Cambios sin guardar')
        ->set('targetPoints', '12')
        ->call('cancel')
        ->assertRedirect(route('business.pass'));

    $this->assertDatabaseCount('promotions', 0);
});

it('uses the current business timezone after a draft is mounted', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
    $promotion = app(SavePromotionDraft::class)->handle($owner, promotionEditorDraftInput());

    $component = Livewire::actingAs($owner)
        ->test('pages::business.promotion', ['promotion' => $promotion])
        ->assertDontSee('America/La_Paz');

    $business->update(['timezone' => 'Europe/Madrid']);

    $component->call('$refresh')->assertDontSee('Europe/Madrid')
        ->assertSee('La fecha de fin incluye ese día completo. La recompensa también vence al terminar la promoción.');
});

/**
 * Build a valid draft payload used by Livewire editor feature tests.
 *
 * @param  array<string, mixed>  $overrides  Draft values replacing the valid defaults.
 * @return array<string, mixed> Valid draft fields and configured extra-point rules.
 */
function promotionEditorDraftInput(array $overrides = []): array
{
    return array_replace([
        'local_start_date' => '2026-11-01',
        'local_end_date' => '2026-11-07',
        'target_points' => 8,
        'reward_title' => 'A coffee with pastry',
        'reward_description' => 'Any small coffee and pastry.',
        'extra_points' => [],
    ], $overrides);
}

/**
 * Build one rule entry while allowing tests to supply untrusted weekday scalar input.
 *
 * @param  int|string  $weekday  ISO weekday value in integer or client-submitted string form.
 * @param  string|null  $start  Window start in HH:MM format, or null for all day.
 * @param  string|null  $end  Window end in HH:MM format, or null for all day.
 * @param  int  $multiplier  Supported total multiplier value.
 * @return array{weekday: int|string, start_time: string|null, end_time: string|null, multiplier: int} Rule entry preserving the supplied weekday representation.
 */
function promotionEditorWindow(int|string $weekday, ?string $start, ?string $end, int $multiplier): array
{
    return [
        'weekday' => $weekday,
        'start_time' => $start,
        'end_time' => $end,
        'multiplier' => $multiplier,
    ];
}
