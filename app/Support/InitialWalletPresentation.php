<?php

namespace App\Support;

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use Carbon\CarbonImmutable;

/**
 * Resolves real initial content for a new pass with no progress or prior Promotion relationship.
 */
class InitialWalletPresentation
{
    /**
     * Uses the authoritative PostgreSQL operation clock without writing loyalty facts.
     *
     * @param  DatabaseClock  $clock  Shared source of the operation instant.
     */
    public function __construct(private DatabaseClock $clock) {}

    /**
     * Selects current published Promotion terms or waiting content using one database instant.
     *
     * Serves new passes only and never synthesizes a historical result or participation.
     *
     * @param  Business  $business  Persisted owner whose current Promotion is presented.
     * @return array{
     *     state: 'waiting'|'active',
     *     progress: ?string,
     *     text_modules: list<array{id: string, header: string, body: string}>
     * } Spanish initial content, without credentials or provider fields.
     */
    public function resolve(Business $business): array
    {
        $reading = $this->clock->captureForBusinessTimezone($business->timezone);

        $promotion = $business->promotions()
            ->with('extraPoints')
            ->where('status', PromotionStatus::Published)
            ->whereNull('cancelled_at')
            ->where('starts_at', '<=', $reading['instant'])
            ->where('ends_at', '>', $reading['instant'])
            ->first();

        if ($promotion === null) {
            return $this->waitingPresentation();
        }

        $localInstant = CarbonImmutable::parse($reading['instant'])
            ->setTimezone($promotion->timezone_snapshot);

        return $this->activePresentation($promotion, $localInstant);
    }

    // -----------------------------------------------------------------------------
    // initial presentation states
    // -----------------------------------------------------------------------------

    /**
     * Builds waiting content without progress or Promotion terms.
     *
     * @return array{
     *     state: 'waiting',
     *     progress: null,
     *     text_modules: list<array{id: string, header: string, body: string}>
     * } Waiting content for a new pass.
     */
    private function waitingPresentation(): array
    {
        return [
            'state' => 'waiting',
            'progress' => null,
            'text_modules' => [
                [
                    'id' => 'promotion',
                    'header' => __('👀 PRÓXIMA PROMOCIÓN'),
                    'body' => __('business.pass.waiting_state_heading')
                        .' '.__('business.pass.waiting_state_description'),
                ],
            ],
        ];
    }

    /**
     * Builds zero-progress content from the active Promotion and its local operation instant.
     *
     * Uses the first matching extra-points window and the inclusive local deadline.
     * Does not create participation, visits or reward entitlements.
     *
     * @param  Promotion  $promotion  Current published Promotion with extraPoints already loaded.
     * @param  CarbonImmutable  $localInstant  Operation instant in the Promotion's frozen timezone.
     * @return array{
     *     state: 'active',
     *     progress: string,
     *     text_modules: list<array{id: string, header: string, body: string}>
     * } Initial content reflecting the active Promotion's terms.
     */
    private function activePresentation(
        Promotion $promotion,
        CarbonImmutable $localInstant,
    ): array {
        $localTime = $localInstant->format('H:i:s.u');

        $window = $promotion->extraPoints->first(
            fn ($window) => $window->weekday === $localInstant->isoWeekday()
                && (
                    $window->start_time === null
                    || (
                        $localTime >= $window->start_time
                        && $localTime < $window->end_time
                    )
                ),
        );

        $points = $window->multiplier ?? 1;

        $deadline = CarbonImmutable::parse($promotion->ends_at)
            ->setTimezone($promotion->timezone_snapshot)
            ->subDay();

        $deadline->locale('es');

        $endDate = $deadline->translatedFormat('d M Y');
        $endDate = mb_strtoupper(str_replace('.', '', $endDate));

        $target = $promotion->target_points;

        return [
            'state' => 'active',
            'progress' => __(':progress / :target puntos', [
                'progress' => 0,
                'target' => $target,
            ]),
            'text_modules' => [
                [
                    'id' => 'promotion',
                    'header' => __('🎯 PROMOCIÓN ACTUAL'),
                    'body' => __('Consigue :target puntos hasta el :date.', [
                        'target' => $target,
                        'date' => $endDate,
                    ]),
                ],
                [
                    'id' => 'next_action',
                    'header' => __('Tu progreso'),
                    'body' => __('Te faltan :points puntos.', [
                        'points' => $target,
                    ]),
                ],
                [
                    'id' => 'visit',
                    'header' => __('Valor de tu visita'),
                    'body' => $points > 1
                        ? __('⚡ Ahora tu visita vale :points puntos.', [
                            'points' => $points,
                        ])
                        : __('Tu visita ahora vale 1 punto.'),
                ],
                [
                    'id' => 'reward',
                    'header' => __('Tu recompensa'),
                    'body' => __('🎁 :reward', [
                        'reward' => $promotion->reward_title,
                    ]),
                ],
                [
                    'id' => 'deadline',
                    'header' => __('Fecha límite'),
                    'body' => __('Válido hasta el :date', [
                        'date' => $endDate,
                    ]),
                ],
            ],
        ];
    }
}
