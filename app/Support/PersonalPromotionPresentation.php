<?php

namespace App\Support;

use App\Models\Business;
use App\Models\CustomerPass;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use IntlException;
use stdClass;
use UnexpectedValueException;

/** Reads one coherent personal snapshot without granting merchant authority or changing domain facts. */
class PersonalPromotionPresentation
{
    /**
     * Receives the authoritative PostgreSQL consultation clock.
     *
     * @param  DatabaseClock  $clock  Captures one instant for every fact in the snapshot.
     */
    public function __construct(private readonly DatabaseClock $clock) {}

    /**
     * Projects the current offer and relevant personal facts, never a history catalog.
     *
     * @param  Business  $business  Signature-authorized Business.
     * @param  CustomerPass  $customerPass  Pass resolved through that Business.
     * @return array{state: string, reward: ?string, description: ?string, points: int, target: ?int, visit_points: int, date: ?string, redeemed_date: ?string} Read-only presentation facts.
     *
     * @throws UnexpectedValueException When the authorized scope disappears during consultation.
     * @throws IntlException When a frozen local date cannot be formatted.
     */
    public function build(Business $business, CustomerPass $customerPass): array
    {
        // read all personal facts against one consultation instant and SQL snapshot
        $capturedAt = $this->clock->capture($business->timezone)->instant;
        $snapshot = $this->loadSnapshot($business, $customerPass, $capturedAt);

        // interpret the selected offer without changing its underlying facts
        $state = $this->determineState($snapshot, $capturedAt);
        $dates = $this->presentationDates($snapshot, $state);

        return [
            'state' => $state,
            'reward' => $snapshot->reward_title,
            'description' => $snapshot->reward_description,
            'points' => (int) $snapshot->points,
            'target' => $snapshot->target_points === null ? null : (int) $snapshot->target_points,
            'visit_points' => (int) $snapshot->visit_points,
            'date' => $dates['date'],
            'redeemed_date' => $dates['redeemed_date'],
        ];
    }

    /**
     * Classifies the selected offer by cancellation, time boundaries and entitlement facts.
     *
     * @param  stdClass  $snapshot  Personal facts returned by the scoped consultation.
     * @param  string  $capturedAt  The same PostgreSQL instant used by the consultation.
     * @return string Presentation state, or waiting when no promotion was selected.
     */
    private function determineState(stdClass $snapshot, string $capturedAt): string
    {
        if ($snapshot->promotion_id === null) {
            return 'waiting';
        }

        $consultationAt = CarbonImmutable::parse($capturedAt);
        $startsAt = CarbonImmutable::parse($snapshot->starts_at);
        $endsAt = CarbonImmutable::parse($snapshot->ends_at);

        return match (true) {
            $snapshot->cancelled_at !== null => 'cancelled',
            $startsAt->greaterThan($consultationAt) => 'scheduled',
            $snapshot->redeemed_at !== null => 'redeemed',
            $endsAt->lessThanOrEqualTo($consultationAt) => $snapshot->reward_id !== null ? 'expired' : 'ended',
            $snapshot->reward_id !== null => 'available',
            (int) $snapshot->points > 0 => 'earning',
            default => 'zero',
        };
    }

    /**
     * Formats the offer boundary and redemption dates in the frozen Promotion timezone.
     *
     * @param  stdClass  $snapshot  Selected promotion and entitlement timestamps.
     * @param  string  $state  State already determined from the consultation instant.
     * @return array{date: ?string, redeemed_date: ?string} Localized dates for the presentation.
     *
     * @throws IntlException When ICU cannot format either date.
     */
    private function presentationDates(stdClass $snapshot, string $state): array
    {
        if ($snapshot->promotion_id === null) {
            return [
                'date' => null,
                'redeemed_date' => null,
            ];
        }

        $timezone = $snapshot->timezone_snapshot;

        // scheduled offers display their start; other offers display the last included local date
        $displayAt = $state === 'scheduled'
            ? CarbonImmutable::parse($snapshot->starts_at)
            : CarbonImmutable::parse($snapshot->ends_at)->setTimezone($timezone)->subDay();

        $displayDate = RegionalDate::formatMedium($displayAt->setTimezone($timezone));
        $redeemedDate = $snapshot->redeemed_at === null
            ? null
            : RegionalDate::formatMedium(CarbonImmutable::parse($snapshot->redeemed_at)->setTimezone($timezone));

        return [
            'date' => $displayDate,
            'redeemed_date' => $redeemedDate,
        ];
    }

    /**
     * Reads the selected offer, progress and entitlement in one scoped PostgreSQL statement.
     *
     * @param  Business  $business  Authorized owner of the pass and promotions.
     * @param  CustomerPass  $customerPass  Pass belonging to the authorized owner.
     * @param  string  $capturedAt  Trusted PostgreSQL consultation instant.
     * @return stdClass Selected facts, with nullable promotion fields when no offer exists.
     *
     * @throws UnexpectedValueException When the scoped pass is no longer available.
     */
    private function loadSnapshot(Business $business, CustomerPass $customerPass, string $capturedAt): stdClass
    {
        $snapshot = DB::selectOne(<<<'SQL'
            WITH consultation AS (
                SELECT ?::timestamptz AS instant, ?::bigint AS business_id, ?::bigint AS pass_id
            )
            SELECT offer.id AS promotion_id, offer.starts_at, offer.ends_at, offer.cancelled_at,
                   offer.timezone_snapshot, offer.target_points, offer.reward_title, offer.reward_description,
                   reward.id AS reward_id, reward.redeemed_at,
                   COALESCE((SELECT SUM(visit.awarded_points) FROM visits AS visit
                             WHERE visit.business_id = scope.business_id AND visit.customer_pass_id = pass.id
                               AND visit.promotion_id = offer.id), 0) AS points,
                   COALESCE((SELECT extra.multiplier FROM promotion_multiplier_windows AS extra
                             WHERE extra.promotion_id = offer.id
                               AND extra.weekday = EXTRACT(ISODOW FROM scope.instant AT TIME ZONE offer.timezone_snapshot)
                               AND (extra.start_time IS NULL
                                    OR (extra.start_time <= (scope.instant AT TIME ZONE offer.timezone_snapshot)::time
                                        AND extra.end_time > (scope.instant AT TIME ZONE offer.timezone_snapshot)::time))), 1) AS visit_points
            FROM consultation AS scope
            JOIN customer_passes AS pass ON pass.id = scope.pass_id AND pass.business_id = scope.business_id
            LEFT JOIN LATERAL (
                SELECT promotion.* FROM promotions AS promotion
                WHERE promotion.business_id = scope.business_id
                  AND promotion.status IN ('published', 'cancelled')
                  AND (promotion.cancelled_at IS NULL OR promotion.cancelled_at <= scope.instant)
                ORDER BY CASE
                    WHEN promotion.cancelled_at IS NULL AND promotion.starts_at <= scope.instant AND promotion.ends_at > scope.instant THEN 0
                    WHEN promotion.cancelled_at IS NULL AND promotion.starts_at > scope.instant THEN 1
                    ELSE 2 END,
                    CASE WHEN promotion.cancelled_at IS NULL AND promotion.starts_at > scope.instant THEN promotion.starts_at END ASC,
                    LEAST(promotion.ends_at, COALESCE(promotion.cancelled_at, promotion.ends_at)) DESC,
                    promotion.id DESC
                LIMIT 1
            ) AS offer ON true
            LEFT JOIN reward_entitlements AS reward ON reward.business_id = scope.business_id
                AND reward.customer_pass_id = pass.id AND reward.promotion_id = offer.id
            SQL, [$capturedAt, $business->id, $customerPass->id]);

        if ($snapshot === null) {
            throw new UnexpectedValueException('Authorized consultation scope is unavailable.');
        }

        return $snapshot;
    }
}
