<?php

namespace App\Support;

use App\Data\Wallet\WalletPresentation;
use App\Data\Wallet\WalletTextModule;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Models\RewardEntitlement;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Lang;
use IntlDateFormatter;
use IntlException;
use InvalidArgumentException;
use PDOException;

/**
 * Selects pass content from domain facts, without credentials, provider calls or domain writes.
 *
 * build() chooses active content or the Business overview and adds independent redemption history.
 * WalletPresentation carries progress and named sections, not a visual layout.
 * GoogleWalletPassCreator maps those values to an Object and places them through the Class template.
 * Generated copy follows Laravel's active/fallback locale; merchant-authored Reward text stays intact.
 */
class WalletPresentationBuilder
{
    /**
     * Receives the authoritative presentation clock.
     *
     * @param  DatabaseClock  $clock  Captures one PostgreSQL instant for this reading.
     */
    public function __construct(private DatabaseClock $clock) {}

    /**
     * Builds active content or the Business overview, with independent personal redemption history.
     *
     * A null pass means initial issuance: zero earned points and no personal redemption history.
     * An existing pass supplies immutable awarded totals and persisted entitlement facts, never new writes.
     * One database instant selects all Promotions; its raw string preserves precision and offset in SQL.
     * Carbon conversion is reserved for local calendar and multiplier calculations.
     *
     * @param  Business  $business  Persisted owner selected by the trusted caller.
     * @param  CustomerPass|null  $pass  Same-Business persisted identity, or null for initial issuance.
     * @return WalletPresentation Named content modules; the provider Class owns their visual order.
     *
     * @throws InvalidArgumentException If the pass is invalid or its progress translation is not a string.
     * @throws IntlException If ICU cannot format a presentation date.
     * @throws QueryException If authoritative presentation facts cannot be read.
     * @throws PDOException If the database driver fails.
     */
    public function build(Business $business, ?CustomerPass $pass = null): WalletPresentation
    {
        // validate ownership and capture one database instant
        if (
            $pass !== null
            && (! $pass->exists || $pass->business_id !== $business->id)
        ) {
            throw new InvalidArgumentException('Wallet presentation requires a persisted same-Business pass.');
        }

        $instant = $this->clock->capture($business->timezone)->instant;

        // prefer the active promotion; otherwise show the business overview
        $promotion = $business->promotions()
            ->with('extraPoints')
            ->where('status', PromotionStatus::Published)
            ->whereNull('cancelled_at')
            ->where('starts_at', '<=', $instant)
            ->where('ends_at', '>', $instant)
            ->first();

        if ($promotion === null) {
            return $this->inactiveContent($business, $pass, $instant);
        }

        // read personal history, entitlement and immutable awarded points in that order
        $latest = $this->latestRedemption($business, $pass);
        $entitlement = $pass === null ? null : RewardEntitlement::query()
            ->where('business_id', $business->id)
            ->where('customer_pass_id', $pass->id)
            ->where('promotion_id', $promotion->id)
            ->first();
        $points = $pass === null ? 0 : (int) Visit::query()
            ->where('business_id', $business->id)
            ->where('customer_pass_id', $pass->id)
            ->where('promotion_id', $promotion->id)
            ->sum('awarded_points');

        return $this->activeContent(
            $promotion,
            CarbonImmutable::parse($instant),
            $points,
            $entitlement,
            $latest,
        );
    }

    /**
     * Builds in-progress, Reward-available or redeemed content while the Promotion remains active.
     *
     * Persisted entitlement facts select the wording: redeemed first, then available, otherwise earning.
     * Progress appears only while earning; hiding it never rewrites the immutable awarded total.
     *
     * card_primary holds the Reward/date/context summary.
     * card_secondary is absent while a Promotion is active.
     * details_primary and its description/date cells expand that summary.
     * details_context repeats its guidance.
     * details_history exists only for a real redemption and is selected only in swipe-up details.
     *
     * The return's argument order groups content only; buildClass() determines its displayed order.
     *
     * @param  Promotion  $promotion  Active frozen terms with multiplier windows loaded.
     * @param  CarbonImmutable  $instant  Single database observation instant.
     * @param  int  $points  Sum of immutable awarded points, not a recalculation from current rules.
     * @param  RewardEntitlement|null  $entitlement  Persisted unlock and redemption facts.
     * @param  WalletTextModule|null  $latest  Independent latest redemption, absent when none exists.
     * @return WalletPresentation Relevant content, with actual progress only while no Reward entitlement exists.
     *
     * @throws InvalidArgumentException If the progress translation is not a string.
     * @throws IntlException If ICU cannot format a presentation date.
     */
    private function activeContent(
        Promotion $promotion,
        CarbonImmutable $instant,
        int $points,
        ?RewardEntitlement $entitlement,
        ?WalletTextModule $latest,
    ): WalletPresentation {
        // evaluate the visit value using the promotion's frozen timezone and windows
        $localTime = $instant->setTimezone($promotion->timezone_snapshot);
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

        // subtract a local calendar day from the exclusive end, not a UTC day
        $deadline = $this->formatDate(
            CarbonImmutable::parse($promotion->ends_at)
                ->setTimezone($promotion->timezone_snapshot)
                ->subDay(),
            $promotion->timezone_snapshot,
        );

        // select one complete message set instead of overwriting earlier states
        [$heading, $dateLabel, $validity, $context] = match (true) {
            $entitlement?->redeemed_at !== null => [
                __('wallet.redeemed.title'),
                __('wallet.labels.redemption_date'),
                __('wallet.redeemed.date', [
                    'date' => $this->formatDate(CarbonImmutable::parse($entitlement->redeemed_at), $promotion->timezone_snapshot),
                ]),
                __('wallet.redeemed.thanks'),
            ],
            $entitlement !== null => [
                __('wallet.completed.title'),
                __('wallet.labels.deadline'),
                __('wallet.completed.deadline', ['date' => $deadline]),
                __('wallet.completed.visit'),
            ],
            default => [
                __('wallet.active.title'),
                __('wallet.labels.deadline'),
                __('wallet.active.deadline', ['date' => $deadline]),
                $visitPoints > 1
                    ? __('wallet.active.multiplier', ['points' => $visitPoints])
                    : __('wallet.active.visit'),
            ],
        };

        return new WalletPresentation(
            $entitlement === null ? Lang::string('wallet.active.progress', [
                'progress' => $points,
                'target' => $promotion->target_points,
            ]) : null,
            ...[
                new WalletTextModule('card_primary', $heading, $promotion->reward_title."\n".$validity."\n\n".$context),
                ...$this->promotionDetails($promotion, 'primary', $heading, $dateLabel, $validity),
                new WalletTextModule('details_context', '', $context),
                ...($latest === null ? [] : [$latest]),
            ],
        );
    }

    /**
     * Builds the last effective Promotion and next announced Promotion without requiring participation.
     *
     * card_primary = previous Promotion.
     * card_secondary = upcoming.
     * Each absent announcement has friendly copy.
     *
     * Each real Promotion supplies separate Reward/description/date details; progress is always null.
     *
     * A real personal redemption adds details_history independently, never an empty-history invitation.
     * The Class falls back to a card message only when that announcement has no Promotion details.
     * Previous selection uses the earlier of cancellation and natural end, with higher ID breaking ties.
     * Cancellation at/before start is excluded; upcoming selection uses earliest start, then lowest ID.
     *
     * @param  Business  $business  Owner of both public Promotion selections.
     * @param  CustomerPass|null  $pass  Same-Business identity used only for personal redemption history.
     * @param  string  $instant  Original timestamp from this build's database clock reading.
     * @return WalletPresentation Both announcement slots and their available details, with optional personal history.
     *
     * @throws IntlException If ICU cannot format a presentation date.
     * @throws QueryException If authoritative Promotion or redemption facts cannot be read.
     * @throws PDOException If the database driver fails.
     */
    private function inactiveContent(Business $business, ?CustomerPass $pass, string $instant): WalletPresentation
    {
        // select the latest effective end, excluding cancellations at or before the scheduled start
        $previous = $business->promotions()
            ->whereIn('status', [PromotionStatus::Published, PromotionStatus::Cancelled])
            ->where('starts_at', '<', $instant)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query
                    ->whereNull('cancelled_at')
                    ->where('ends_at', '<=', $instant))
                ->orWhere(fn ($query) => $query
                    ->whereColumn('cancelled_at', '>', 'starts_at')
                    ->where('cancelled_at', '<=', $instant)))
            ->orderByRaw('LEAST(ends_at, cancelled_at) DESC')
            ->orderByDesc('id')
            ->first();

        // announce only the nearest published, non-cancelled future promotion
        $upcoming = $business->promotions()
            ->where('status', PromotionStatus::Published)
            ->whereNull('cancelled_at')
            ->where('starts_at', '>', $instant)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->first();

        // keep both overview positions informative without inventing missing promotion terms
        $previousHeading = __('wallet.previous.title');
        $upcomingHeading = __('wallet.upcoming.title');
        $previousBody = __('wallet.previous.empty');
        $upcomingBody = __('wallet.upcoming.empty');
        $details = [];

        if ($previous !== null) {
            $cancelled = $previous->cancelled_at !== null
                && $previous->cancelled_at < $previous->ends_at;
            $endedAt = $cancelled
                ? CarbonImmutable::parse($previous->cancelled_at)
                : CarbonImmutable::parse($previous->ends_at)
                    ->setTimezone($previous->timezone_snapshot)
                    ->subDay();
            $ending = __($cancelled ? 'wallet.previous.cancelled' : 'wallet.previous.ended', [
                'date' => $this->formatDate($endedAt, $previous->timezone_snapshot),
            ]);
            $previousBody = $previous->reward_title."\n".$ending;
            $details = $this->promotionDetails(
                $previous,
                'primary',
                $previousHeading,
                __($cancelled ? 'wallet.labels.cancellation_date' : 'wallet.labels.end_date'),
                $ending,
            );
        }

        if ($upcoming !== null) {
            $starting = __('wallet.upcoming.starts', [
                'date' => $this->formatDate(CarbonImmutable::parse($upcoming->starts_at), $upcoming->timezone_snapshot),
            ]);
            $upcomingBody = $upcoming->reward_title."\n".$starting;
            $details = [
                ...$details,
                ...$this->promotionDetails($upcoming, 'secondary', $upcomingHeading, __('wallet.labels.start_date'), $starting),
            ];
        }

        $latest = $this->latestRedemption($business, $pass);

        return new WalletPresentation(
            null,
            new WalletTextModule('card_primary', $previousHeading, $previousBody),
            new WalletTextModule('card_secondary', $upcomingHeading, $upcomingBody),
            ...$details,
            ...($latest === null ? [] : [$latest]),
        );
    }

    /**
     * Separates one Promotion's Reward, optional description and date into ordered detail cells.
     *
     * Missing descriptions produce no cell; merchant text is neither translated nor recased.
     * Both announcement groups plus their card summaries, history and manual code fit ten modules.
     *
     * @param  Promotion  $promotion  Frozen Reward title and optional description.
     * @param  'primary'|'secondary'  $position  Corresponding main-card role.
     * @param  string  $heading  Localized Reward or announcement heading.
     * @param  string  $dateLabel  Localized label for this state's date.
     * @param  string  $date  Already formatted localized date sentence.
     * @return list<WalletTextModule> Reward, optional description, then applicable date.
     */
    private function promotionDetails(Promotion $promotion, string $position, string $heading, string $dateLabel, string $date): array
    {
        $role = 'details_'.$position;
        $sections = [new WalletTextModule($role, $heading, $promotion->reward_title)];

        if ($promotion->reward_description !== null && $promotion->reward_description !== '') {
            $sections[] = new WalletTextModule($role.'_description', __('wallet.labels.description'), $promotion->reward_description);
        }

        $sections[] = new WalletTextModule($role.'_date', $dateLabel, $date);

        return $sections;
    }

    /**
     * Builds details_history from the latest completed personal redemption, across Promotions.
     *
     * Only this pass's redeemed entitlements qualify; unlocked but unredeemed Rewards are not history.
     * No redemption yields no module; history is not an invitation to earn another Reward.
     *
     * @param  Business  $business  Scope shared by the pass and its entitlements.
     * @param  CustomerPass|null  $pass  Existing identity, absent during initial issuance.
     * @return WalletTextModule|null Real redemption content, or null when no qualifying history exists.
     *
     * @throws IntlException If ICU cannot format a presentation date.
     * @throws QueryException If the personal redemption history cannot be read.
     * @throws PDOException If the database driver fails.
     */
    private function latestRedemption(Business $business, ?CustomerPass $pass): ?WalletTextModule
    {
        // read only completed personal redemptions, preserving timestamp and ID order
        $entitlement = $pass === null ? null : RewardEntitlement::query()
            ->with('promotion')
            ->where('business_id', $business->id)
            ->where('customer_pass_id', $pass->id)
            ->whereNotNull('redeemed_at')
            ->orderByDesc('redeemed_at')
            ->orderByDesc('id')
            ->first();

        if ($entitlement === null) {
            return null;
        }

        // use the redeemed promotion's own reward and timezone, not the current promotion's
        $promotion = $entitlement->promotion;

        return new WalletTextModule(
            'details_history',
            __('wallet.history.title'),
            $promotion->reward_title."\n".__('wallet.redeemed.date', [
                'date' => $this->formatDate(CarbonImmutable::parse($entitlement->redeemed_at), $promotion->timezone_snapshot),
            ]),
        );
    }

    /**
     * Formats a date for the active locale without changing the promotion's local day.
     *
     * The frozen timezone chooses the calendar day; Laravel's active locale chooses its regional format.
     * Callers convert exclusive Promotion ends to the last included local day before calling this method.
     * ICU's date-only medium style preserves native ordering, casing and punctuation without a fixed pattern.
     *
     * @param  CarbonImmutable  $instant  Stored event instant or derived inclusive-end date.
     * @param  string  $timezone  Frozen IANA timezone.
     * @return string Regional date including its year, without a time component.
     *
     * @throws IntlException If ICU cannot format the date.
     */
    private function formatDate(CarbonImmutable $instant, string $timezone): string
    {
        $date = IntlDateFormatter::formatObject(
            $instant->setTimezone($timezone),
            [IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE],
            app()->getLocale(),
        );

        if ($date === false) {
            throw new IntlException('Wallet date formatting failed.');
        }

        return $date;
    }
}
