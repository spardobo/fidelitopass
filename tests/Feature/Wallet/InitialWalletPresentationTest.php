<?php

use App\Data\Time\DatabaseTime;
use App\Data\Wallet\WalletTextModule;
use App\Integrations\GoogleWallet\GoogleWalletPassCreator;
use App\Models\Business;
use App\Models\Promotion;
use App\Support\DatabaseClock;
use App\Support\InitialWalletPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mock(GoogleWalletPassCreator::class)
        ->shouldReceive('create')
        ->never();
    $this->business = Business::factory()->create(['timezone' => 'America/La_Paz']);
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()
        ->with('America/La_Paz')->andReturn(new DatabaseTime('2030-01-02 12:00:00+00', '2030-01-02'));
});

it('renders only the real waiting state when no active promotion applies', function (string $status, ?string $starts, ?string $ends) {
    initialWalletPromotion($this->business, ['status' => $status, 'starts_at' => $starts, 'ends_at' => $ends,
        'cancelled_at' => $status === 'cancelled' ? '2030-01-02 11:00:00+00' : null,
        ...($status === 'draft' ? ['timezone_snapshot' => null, 'local_start_date' => '2030-01-03', 'local_end_date' => '2030-01-04'] : [])]);
    initialWalletPromotion(Business::factory()->create(), ['reward_title' => 'Foreign reward']);

    $content = app(InitialWalletPresentation::class)->build($this->business);
    $presentation = [
        'progress' => $content->progress,
        'text_modules' => array_map(static fn (WalletTextModule $section): array => [
            'id' => $section->id, 'header' => $section->header, 'body' => $section->body,
        ], $content->sections),
    ];

    expect($presentation['progress'])->toBeNull()
        ->and($presentation['text_modules'])->toBe([
            [
                'id' => 'promotion',
                'header' => '⏳ Espera a la próxima promoción',
                'body' => 'Tu tarjeta ya está lista. Aquí aparecerá la próxima promoción del negocio.',
            ],
        ])
        ->and(json_encode($presentation, JSON_UNESCAPED_UNICODE))
        ->not->toContain('Foreign reward', '15 puntos', 'Hamburguesa', 'Café central');
    expect(DB::table('customer_passes')->count())->toBe(0)
        ->and(DB::table('promotion_participations')->count())->toBe(0);
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

    $content = app(InitialWalletPresentation::class)->build($this->business);
    $presentation = [
        'progress' => $content->progress,
        'text_modules' => array_map(static fn (WalletTextModule $section): array => [
            'id' => $section->id, 'header' => $section->header, 'body' => $section->body,
        ], $content->sections),
    ];

    expect($presentation['progress'])->toBe('0 / 8 puntos')
        ->and(array_column($presentation['text_modules'], 'id'))->toBe(['next_action', 'visit', 'reward', 'deadline', 'compact_promotion'])
        ->and($presentation['text_modules'][0]['body'])->toBe('Te faltan 8 puntos.')
        ->and($presentation['text_modules'][1]['body'])->toContain((string) $expectedPoints)
        ->and($presentation['text_modules'][2])->toBe([
            'id' => 'reward',
            'header' => 'PROMOCIÓN',
            'body' => 'Un pan artesanal',
        ])
        ->and($presentation['text_modules'][3]['body'])->toBe('Válido hasta el 04 ENE 2030')
        ->and($presentation['text_modules'][4]['body'])->toBe(
            "Un pan artesanal\nVálido hasta el 04 ENE 2030\n\n".($expectedPoints > 1
                ? '⚡ Ahora tu visita vale 3 puntos.'
                : 'Tu visita ahora vale 1 punto.'),
        )
        ->and(json_encode($presentation, JSON_UNESCAPED_UNICODE))->not->toContain('Consigue');
    expect(DB::table('promotion_participations')->count())->toBe(0)
        ->and(DB::table('visits')->count())->toBe(0)->and(DB::table('reward_entitlements')->count())->toBe(0);
})->with([
    'window start inclusive' => ['07:00:00', '08:00:00', 3],
    'window end exclusive' => ['06:00:00', '07:00:00', 1],
    'outside window' => ['08:00:00', '09:00:00', 1],
]);

it('retains the optional real Reward description with its title without inventing absent content', function (?string $description, string $expected) {
    initialWalletPromotion($this->business, ['reward_description' => $description]);

    $content = app(InitialWalletPresentation::class)->build($this->business);
    $reward = collect($content->sections)->firstWhere('id', 'reward');

    expect($reward->header)->toBe('PROMOCIÓN');
    expect($reward->body)->toBe($expected);
    expect($content->progress)->toBe('0 / 8 puntos');
    $compact = collect($content->sections)->firstWhere('id', 'compact_promotion');
    expect($compact->header)->toBe('PROMOCIÓN');
    expect($compact->body)->toBe("Un pan artesanal\nVálido hasta el 04 ENE 2030\n\nTu visita ahora vale 1 punto.");
})->with([
    'real description' => ['Pan integral de masa madre.', "Un pan artesanal\nPan integral de masa madre."],
    'absent description' => [null, 'Un pan artesanal'],
    'empty description' => ['', 'Un pan artesanal'],
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
