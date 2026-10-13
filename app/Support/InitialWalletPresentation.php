<?php

namespace App\Support;

use App\Data\Wallet\WalletPresentation;
use App\Data\Wallet\WalletTextModule;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Carbon\Exceptions\InvalidTimeZoneException;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * Builds initial pass content from the business's current promotion.
 *
 * This does not create a participation or update any existing customer's progress.
 */
class InitialWalletPresentation
{
    /**
     * Receives the clock used for promotion selection.
     *
     * @param  DatabaseClock  $clock  Source of one authoritative database instant.
     */
    public function __construct(private DatabaseClock $clock) {}

    /**
     * Builds active-promotion content or waiting content using one database time reading.
     *
     * @param  Business  $business  Persisted owner of the new pass.
     * @return WalletPresentation Initial progress and ordered sections, without pass credentials.
     *
     * @throws QueryException If the clock, promotion or eager-loading query fails.
     * @throws PDOException If the database driver fails.
     * @throws InvalidFormatException If a stored timestamp cannot be parsed.
     * @throws InvalidTimeZoneException If a stored timezone cannot be used.
     */
    public function build(Business $business): WalletPresentation
    {
        $time = $this->clock->capture($business->timezone);

        $promotion = $business->promotions()
            ->with('extraPoints')
            ->where('status', PromotionStatus::Published)
            ->whereNull('cancelled_at')
            ->where('starts_at', '<=', $time->instant)
            ->where('ends_at', '>', $time->instant)
            ->first();

        if ($promotion === null) {
            return $this->buildWaitingContent();
        }

        $localTime = CarbonImmutable::parse($time->instant)
            ->setTimezone($promotion->timezone_snapshot);

        return $this->buildActiveContent($promotion, $localTime);
    }

    /**
     * Builds the message displayed when there is no active promotion.
     *
     * @return WalletPresentation Waiting content, without points or invented promotion terms.
     */
    private function buildWaitingContent(): WalletPresentation
    {
        return new WalletPresentation(
            null,
            new WalletTextModule(
                'promotion',
                __('business.pass.waiting_card_title'),
                __('business.pass.waiting_state_heading').' '.__('business.pass.waiting_state_description'),
            ),
        );
    }

    /**
     * Builds zero-progress content using the first applicable bonus window and the existing deadline rule.
     *
     * @param  Promotion  $promotion  Published promotion with extraPoints already loaded.
     * @param  CarbonImmutable  $localTime  Operation instant in the promotion's frozen timezone.
     * @return WalletPresentation Zero-progress text and current promotion, visit, reward and deadline sections.
     *
     * @throws InvalidFormatException If the stored promotion deadline cannot be parsed.
     * @throws InvalidTimeZoneException If the stored promotion timezone is invalid.
     */
    private function buildActiveContent(Promotion $promotion, CarbonImmutable $localTime): WalletPresentation
    {
        // preserve the first matching bonus window and its inclusive-start, exclusive-end bounds
        $timeOfDay = $localTime->format('H:i:s.u');
        $window = $promotion->extraPoints->first(
            fn ($window) => $window->weekday === $localTime->isoWeekday()
                && (
                    $window->start_time === null
                    || (
                        $timeOfDay >= $window->start_time
                        && $timeOfDay < $window->end_time
                    )
                ),
        );

        $visitPoints = $window->multiplier ?? 1;

        // render the final included local date without changing the stored deadline
        $finalLocalDate = CarbonImmutable::parse($promotion->ends_at)
            ->setTimezone($promotion->timezone_snapshot)
            ->subDay();

        $finalLocalDate->locale('es');
        $finalLocalDateText = $finalLocalDate->translatedFormat('d M Y');
        $finalLocalDateText = mb_strtoupper(str_replace('.', '', $finalLocalDateText));
        $targetPoints = $promotion->target_points;

        $validity = __('Válido hasta el :date', ['date' => $finalLocalDateText]);
        $visit = $visitPoints > 1
            ? __('⚡ Ahora tu visita vale :points puntos.', ['points' => $visitPoints])
            : __('Tu visita ahora vale 1 punto.');

        $reward = $promotion->reward_title;
        if ($promotion->reward_description !== null && $promotion->reward_description !== '') {
            $reward .= "\n".$promotion->reward_description;
        }

        // keep the actual reward and progress without generated earning terms
        return new WalletPresentation(
            __(':progress / :target puntos', ['progress' => 0, 'target' => $targetPoints]),
            new WalletTextModule(
                'next_action',
                __('Tu progreso'),
                __('Te faltan :points puntos.', ['points' => $targetPoints]),
            ),
            new WalletTextModule(
                'visit',
                __('Valor de tu visita'),
                $visit,
            ),
            new WalletTextModule(
                'reward',
                __('PROMOCIÓN'),
                $reward,
            ),
            new WalletTextModule(
                'deadline',
                __('Fecha límite'),
                $validity,
            ),
            new WalletTextModule(
                'compact_promotion',
                __('PROMOCIÓN'),
                $promotion->reward_title."\n".$validity."\n\n".$visit,
            ),
        );
    }
}
