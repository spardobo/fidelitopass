<?php

use App\Models\Business;
use App\Models\Promotion;
use App\Support\DatabaseClock;
use App\Support\InitialWalletPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->business = Business::factory()->create(['timezone' => 'America/La_Paz']);
    $this->mock(DatabaseClock::class)->shouldReceive('captureForBusinessTimezone')->once()
        ->with('America/La_Paz')->andReturn(['instant' => '2030-01-02 12:00:00+00', 'business_date' => '2030-01-02']);
});

it('renders only the real waiting state when no active promotion applies', function (string $status, ?string $starts, ?string $ends) {
    initialWalletPromotion($this->business, ['status' => $status, 'starts_at' => $starts, 'ends_at' => $ends,
        'cancelled_at' => $status === 'cancelled' ? '2030-01-02 11:00:00+00' : null,
        ...($status === 'draft' ? ['timezone_snapshot' => null, 'local_start_date' => '2030-01-03', 'local_end_date' => '2030-01-04'] : [])]);
    initialWalletPromotion(Business::factory()->create(), ['reward_title' => 'Foreign reward']);

    $presentation = app(InitialWalletPresentation::class)->resolve($this->business);

    expect($presentation['state'])->toBe('waiting')
        ->and($presentation['text_modules'][0])->toMatchArray(['id' => 'promotion', 'header' => '👀 PRÓXIMA PROMOCIÓN'])
        ->and(json_encode($presentation, JSON_UNESCAPED_UNICODE))->toContain('Tu Pase ya está listo.')
        ->not->toContain('Foreign reward', '15 puntos', 'Hamburguesa', 'Café central');
    expect(DB::table('customer_passes')->count())->toBe(0)
        ->and(DB::table('promotion_participations')->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'scheduled' => ['published', '2030-01-03 00:00:00+00', '2030-01-05 00:00:00+00'],
    'ended at exact instant' => ['published', '2030-01-01 00:00:00+00', '2030-01-02 12:00:00+00'],
    'draft' => ['draft', null, null],
    'cancelled' => ['cancelled', '2030-01-01 00:00:00+00', '2030-01-03 00:00:00+00'],
]);

it('maps zero initial progress actual reward inclusive deadline and promotion timezone visit value', function (string $startTime, string $endTime, int $expectedPoints) {
    $promotion = initialWalletPromotion($this->business, [
        'starts_at' => '2030-01-02 12:00:00+00',
        'ends_at' => '2030-01-05 05:00:00+00',
        'timezone_snapshot' => 'America/Bogota',
    ]);
    $promotion->extraPoints()->create(['weekday' => 3, 'start_time' => $startTime, 'end_time' => $endTime, 'multiplier' => 3]);

    $presentation = app(InitialWalletPresentation::class)->resolve($this->business);

    expect($presentation['state'])->toBe('active')->and($presentation['progress'])->toBe('0 / 8 puntos')
        ->and($presentation['text_modules'][0])->toMatchArray([
            'id' => 'promotion', 'header' => '🎯 PROMOCIÓN ACTUAL', 'body' => 'Consigue 8 puntos hasta el 04 ENE 2030.',
        ])
        ->and($presentation['text_modules'][1]['body'])->toBe('Te faltan 8 puntos.')
        ->and($presentation['text_modules'][2]['body'])->toContain((string) $expectedPoints)
        ->and($presentation['text_modules'][3]['body'])->toBe('🎁 Un pan artesanal')
        ->and($presentation['text_modules'][4]['body'])->toBe('Válido hasta el 04 ENE 2030');
    expect(DB::table('promotion_participations')->count())->toBe(0)
        ->and(DB::table('visits')->count())->toBe(0)->and(DB::table('reward_entitlements')->count())->toBe(0);
})->with([
    'window start inclusive' => ['07:00:00', '08:00:00', 3],
    'window end exclusive' => ['06:00:00', '07:00:00', 1],
    'outside window' => ['08:00:00', '09:00:00', 1],
]);

/**
 * Persists synthetic Promotion terms without invoking domain or provider actions.
 *
 * @param  Business  $business  Fixture owner.
 * @param  array<string, mixed>  $overrides  Lifecycle or timing values under test.
 * @return Promotion Persisted fixture, never a fictional issued presentation.
 */
function initialWalletPromotion(Business $business, array $overrides = []): Promotion
{
    $promotion = $business->promotions()->make();
    $promotion->forceFill([
        'status' => 'published', 'target_points' => 8, 'reward_title' => 'Un pan artesanal',
        'timezone_snapshot' => 'America/La_Paz',
        'starts_at' => '2030-01-01 04:00:00+00', 'ends_at' => '2030-01-05 04:00:00+00',
        ...$overrides,
    ])->save();

    return $promotion;
}
