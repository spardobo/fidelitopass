<?php

namespace App\Support;

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
 * Builds Google Wallet Object textModulesData from the current domain state.
 *
 * PromotionContent groups two application-facing surfaces: card is the compact front summary;
 * details contains separate reward, description, date and guidance fields for the expanded view.
 * These group names are internal, not fields accepted by the Google Wallet API.
 *
 * Google TextModuleData uses id, header and body. The Object carries those reusable modules;
 * the Class classTemplateInfo selects them through cardTemplateOverride, detailsTemplateOverride
 * and cardBarcodeSectionDetails. Module order alone does not define their visual placement.
 *
 * The card_* and details_* IDs are application-owned roles, not Google-defined domain names.
 * Progress stays separate because barcode_top is referenced above the barcode and in details.
 * Detail selectors may fall back to card modules; surface groups do not imply exclusive placement.
 * The QR value remains outside this builder; details_code contains only the manual lookup code.
 *
 * @phpstan-type Text array{header: string, body: string}
 * @phpstan-type PromotionDetails array{
 *     reward?: Text,
 *     description?: Text,
 *     date?: Text,
 *     context?: Text
 * }
 * @phpstan-type PromotionContent array{
 *     card: Text,
 *     details?: PromotionDetails,
 *     progress?: Text
 * }
 */
class WalletPresentationBuilder
{
    /**
     * Receives the authoritative presentation clock.
     *
     * @param  DatabaseClock  $clock  Captures one PostgreSQL instant when the caller supplies none.
     */
    public function __construct(private DatabaseClock $clock) {}

    /**
     * Builds the same complete module list for initial issuance and existing-pass updates.
     *
     * Unsaved passes supply a manual code but have no personal history or awarded points.
     * This method reads domain facts; it neither changes them nor calls Google.
     *
     * @param  Business  $business  Owner selected by the trusted caller.
     * @param  CustomerPass  $pass  Prepared or persisted identity belonging to that Business.
     * @param  string|null  $operationAt  Trusted caller's PostgreSQL instant; null captures the current database time.
     * @return list<array{id: string, header: string, body: string}> Ordered text modules, including code and any progress.
     *
     * @throws InvalidArgumentException If ownership is invalid or progress translation is not a string.
     * @throws IntlException If a presentation date cannot be formatted.
     * @throws QueryException If domain facts cannot be read.
     * @throws PDOException If the database driver fails.
     */
    public function build(Business $business, CustomerPass $pass, ?string $operationAt = null): array
    {
        // validate ownership before reading facts or exposing the manual code
        if ($pass->business_id !== $business->id) {
            throw new InvalidArgumentException('Wallet presentation requires a same-Business pass.');
        }

        // select the active promotion at one database instant
        $instant = $operationAt ?? $this->clock->capture($business->timezone)->instant;
        $promotion = $business->promotions()
            ->with('extraPoints')
            ->where('status', PromotionStatus::Published)
            ->whereNull('cancelled_at')
            ->where('starts_at', '<=', $instant)
            ->where('ends_at', '>', $instant)
            ->first();

        // build either the current promotion or the business's two announcements
        if ($promotion !== null) {
            $history = $this->latestRedemption($business, $pass);
            $primary = $this->activePromotion($business, $pass, $promotion, $instant);
            $secondary = null;
        } else {
            $overview = $this->promotionOverview($business, $instant);
            $primary = $overview['previous'];
            $secondary = $overview['upcoming'];
            $history = $this->latestRedemption($business, $pass);
        }

        // define every text ID in one place, including progress and the manual code
        $contentById = [
            'card_primary' => $primary['card'],
            'card_secondary' => $secondary['card'] ?? null,
            'details_primary' => $primary['details']['reward'] ?? null,
            'details_primary_description' => $primary['details']['description'] ?? null,
            'details_primary_date' => $primary['details']['date'] ?? null,
            'details_context' => $primary['details']['context'] ?? null,
            'details_secondary' => $secondary['details']['reward'] ?? null,
            'details_secondary_description' => $secondary['details']['description'] ?? null,
            'details_secondary_date' => $secondary['details']['date'] ?? null,
            'details_history' => $history,
            'barcode_top' => $primary['progress'] ?? null,
            'details_code' => [
                'header' => __('wallet.labels.manual_code'),
                'body' => $pass->manual_code,
            ],
        ];

        // omit absent content, not valid empty headings or zero progress
        $modules = [];

        foreach ($contentById as $id => $text) {
            if ($text === null) {
                continue;
            }

            $modules[] = [
                'id' => $id,
                'header' => $text['header'],
                'body' => $text['body'],
            ];
        }

        return $modules;
    }

    /**
     * Builds the current Reward, date and guidance, with progress only while earning.
     *
     * @param  Business  $business  Owner used to scope entitlement and Visit reads.
     * @param  CustomerPass  $pass  Persisted identity, or an unsaved pass with no history.
     * @param  Promotion  $promotion  Active frozen terms with multiplier windows loaded.
     * @param  string  $instant  Shared database-derived operation timestamp used by this build.
     * @return PromotionContent Complete active content before assigning Google module IDs.
     *
     * @throws InvalidArgumentException If progress translation is not a string.
     * @throws IntlException If a presentation date cannot be formatted.
     * @throws QueryException If entitlement or Visit facts cannot be read.
     * @throws PDOException If the database driver fails.
     */
    private function activePromotion(
        Business $business,
        CustomerPass $pass,
        Promotion $promotion,
        string $instant,
    ): array {
        // read immutable personal facts only for an existing pass
        $entitlement = null;
        $points = 0;

        if ($pass->exists) {
            $entitlement = RewardEntitlement::query()
                ->where('business_id', $business->id)
                ->where('customer_pass_id', $pass->id)
                ->where('promotion_id', $promotion->id)
                ->first();

            $points = (int) Visit::query()
                ->where('business_id', $business->id)
                ->where('customer_pass_id', $pass->id)
                ->where('promotion_id', $promotion->id)
                ->sum('awarded_points');
        }

        // evaluate the frozen multiplier window in the promotion's own timezone
        $localTime = CarbonImmutable::parse($instant)->setTimezone($promotion->timezone_snapshot);
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

        // choose the messages once: redeemed, available, or still earning
        if ($entitlement?->redeemed_at !== null) {
            $heading = __('wallet.redeemed.title');
            $dateLabel = __('wallet.labels.redemption_date');
            $date = __('wallet.redeemed.date', [
                'date' => $this->formatDate(
                    CarbonImmutable::parse($entitlement->redeemed_at),
                    $promotion->timezone_snapshot,
                ),
            ]);
            $context = __('wallet.redeemed.thanks');
        } elseif ($entitlement !== null) {
            $heading = __('wallet.completed.title');
            $dateLabel = __('wallet.labels.deadline');
            $date = __('wallet.completed.deadline', ['date' => $deadline]);
            $context = __('wallet.completed.visit');
        } else {
            $heading = __('wallet.active.title');
            $dateLabel = __('wallet.labels.deadline');
            $date = __('wallet.active.deadline', ['date' => $deadline]);
            $context = $visitPoints > 1
                ? __('wallet.active.multiplier', ['points' => $visitPoints])
                : __('wallet.active.visit');
        }

        // build the promotion's summary and full details with their contextual message
        $promotionContent = $this->basePromotionContent($promotion, $heading, $dateLabel, $date);
        $promotionContent['card']['body'] .= "\n\n".$context;
        $promotionContent['details']['context'] = $this->text('', $context);

        if ($entitlement === null) {
            $progress = Lang::string('wallet.active.progress', [
                'progress' => $points,
                'target' => $promotion->target_points,
            ]);
            $promotionContent['progress'] = [
                'header' => __('wallet.labels.progress'),
                'body' => $progress,
            ];
        }

        return $promotionContent;
    }

    /**
     * Builds previous and upcoming announcements without requiring customer participation.
     *
     * @param  Business  $business  Owner of both public announcements.
     * @param  string  $instant  Shared database-derived operation timestamp used by this build.
     * @return array{previous: PromotionContent, upcoming: PromotionContent} Both announcement positions, including empty messages.
     *
     * @throws IntlException If a presentation date cannot be formatted.
     * @throws QueryException If Promotion facts cannot be read.
     * @throws PDOException If the database driver fails.
     */
    private function promotionOverview(Business $business, string $instant): array
    {
        // select the latest effective ending, excluding promotions cancelled before they ran
        $previous = $business->promotions()
            ->whereIn('status', [PromotionStatus::Published, PromotionStatus::Cancelled])
            ->where('starts_at', '<', $instant)
            ->where(fn ($query) => $query
                ->where(fn ($ended) => $ended
                    ->whereNull('cancelled_at')
                    ->where('ends_at', '<=', $instant)
                )
                ->orWhere(fn ($cancelled) => $cancelled
                    ->whereColumn('cancelled_at', '>', 'starts_at')
                    ->where('cancelled_at', '<=', $instant)
                )
            )
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

        // retain both announcement positions even when their promotion is absent
        $previousHeading = __('wallet.previous.title');
        $upcomingHeading = __('wallet.upcoming.title');
        $previousContent = [
            'card' => $this->text($previousHeading, __('wallet.previous.empty')),
        ];
        $upcomingContent = [
            'card' => $this->text($upcomingHeading, __('wallet.upcoming.empty')),
        ];

        // distinguish a real cancellation from the last included day of a natural ending
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
            $dateLabel = __($cancelled ? 'wallet.labels.cancellation_date' : 'wallet.labels.end_date');
            $previousContent = $this->basePromotionContent($previous, $previousHeading, $dateLabel, $ending);
        }

        // use the upcoming promotion's own frozen start date
        if ($upcoming !== null) {
            $starting = __('wallet.upcoming.starts', [
                'date' => $this->formatDate(
                    CarbonImmutable::parse($upcoming->starts_at),
                    $upcoming->timezone_snapshot,
                ),
            ]);
            $upcomingContent = $this->basePromotionContent(
                $upcoming,
                $upcomingHeading,
                __('wallet.labels.start_date'),
                $starting,
            );
        }

        return [
            'previous' => $previousContent,
            'upcoming' => $upcomingContent,
        ];
    }

    /**
     * Builds a promotion's base card and details before active guidance or progress are added.
     *
     * @param  Promotion  $promotion  Frozen Reward title and optional description.
     * @param  string  $heading  Translated state or announcement heading.
     * @param  string  $dateLabel  Translated label for this state's date.
     * @param  string  $date  Already formatted date sentence.
     * @return PromotionContent Summary, Reward, optional description and date, without provider IDs.
     */
    private function basePromotionContent(
        Promotion $promotion,
        string $heading,
        string $dateLabel,
        string $date,
    ): array {
        $promotionContent = [
            'card' => $this->text($heading, $promotion->reward_title."\n".$date),
            'details' => [
                'reward' => $this->text($heading, $promotion->reward_title),
                'date' => $this->text($dateLabel, $date),
            ],
        ];

        if (
            $promotion->reward_description !== null
            && $promotion->reward_description !== ''
        ) {
            $promotionContent['details']['description'] = $this->text(
                __('wallet.labels.description'),
                $promotion->reward_description,
            );
        }

        return $promotionContent;
    }

    /**
     * Builds history only for this pass's latest completed redemption, across Promotions.
     *
     * @param  Business  $business  Owner used to scope the personal history.
     * @param  CustomerPass  $pass  Unsaved passes have no redemption history.
     * @return Text|null Reward and redemption date, or null when no completed redemption exists.
     *
     * @throws IntlException If a redemption date cannot be formatted.
     * @throws QueryException If redemption facts cannot be read.
     * @throws PDOException If the database driver fails.
     */
    private function latestRedemption(Business $business, CustomerPass $pass): ?array
    {
        // read only completed personal redemptions, never another pass's history
        if (! $pass->exists) {
            return null;
        }

        $entitlement = RewardEntitlement::query()
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

        // retain the redeemed promotion's own reward and timezone
        $promotion = $entitlement->promotion;

        return $this->text(
            __('wallet.history.title'),
            $promotion->reward_title."\n".__('wallet.redeemed.date', [
                'date' => $this->formatDate(
                    CarbonImmutable::parse($entitlement->redeemed_at),
                    $promotion->timezone_snapshot,
                ),
            ]),
        );
    }

    /**
     * Stores one heading and body without a DTO or provider-specific ID.
     *
     * @param  string  $header  Translated heading; an empty heading remains valid.
     * @param  string  $body  Prepared content, including unmodified merchant text.
     * @return Text Heading and body constrained to strings.
     */
    private function text(string $header, string $body): array
    {
        return [
            'header' => $header,
            'body' => $body,
        ];
    }

    /**
     * Formats a local date with its year using Laravel's active locale and ICU ordering.
     *
     * @param  CarbonImmutable  $instant  Event instant or previously derived inclusive-end date.
     * @param  string  $timezone  Frozen Promotion timezone.
     * @return string Regional date without a time component.
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
