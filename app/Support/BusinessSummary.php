<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Promotion;
use App\Models\PromotionMultiplierWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use stdClass;

/**
 * Reads owner-scoped Summary facts without authorizing or executing Visit/Reward operations.
 *
 * @phpstan-type Metrics array{active_passes: int, awarded_points: int, unlocked_rewards: int, redeemed_rewards: int}
 * @phpstan-type Summary array{business: Business, asOf: CarbonImmutable, currentPromotion: Promotion|null, nextScheduled: Promotion|null, lastPromotion: Promotion|null, appearancePrepared: bool, promotionPrepared: bool, statistics: 'waiting'|'available'|'unavailable', metrics: Metrics|null}
 */
class BusinessSummary
{
    /**
     * Re-resolves the owner's Business, Promotion phases and aggregates on every read/retry.
     *
     * Successful facts share one PostgreSQL statement snapshot and one materialized wall-clock instant.
     * A transient aggregate failure discards that attempt entirely and reads fresh context without metrics.
     * If even context is unavailable, the exception propagates; no cached context or zeroes are invented.
     *
     * @param  User  $actor  Authenticated owner supplied by the server, not hydrated Business/Promotion IDs.
     * @return Summary Current context and either known metrics, waiting, or explicitly unavailable statistics.
     *
     * @throws AuthorizationException When the owner is unverified or cannot access the resolved Business.
     * @throws ModelNotFoundException When the actor no longer owns a Business.
     * @throws QueryException When context cannot be read or the query contract is broken.
     */
    public function read(User $actor): array
    {
        if (! $actor->hasVerifiedEmail()) {
            throw new AuthorizationException;
        }

        $statisticsUnavailable = false;
        try {
            $snapshot = $this->snapshot($actor, true);
        } catch (QueryException $exception) {
            // Only availability failures degrade statistics; schema, SQL and permission defects must surface.
            $sqlState = (string) $exception->getCode();
            if (! str_starts_with($sqlState, '08') && ! str_starts_with($sqlState, '53')
                && ! in_array($sqlState, ['57014', '57P01', '57P02', '57P03', '55P03'], true)) {
                throw $exception;
            }

            $statisticsUnavailable = true;
            $snapshot = $this->snapshot($actor, false);
        }

        if ($snapshot === null) {
            throw (new ModelNotFoundException)->setModel(Business::class);
        }

        $business = (new Business)->newFromBuilder(json_decode($snapshot->business, true, flags: JSON_THROW_ON_ERROR));
        Gate::forUser($actor)->authorize('update', $business);
        $currentPromotion = $this->promotion($snapshot->current_promotion);
        if ($currentPromotion !== null) {
            $rules = json_decode($snapshot->extra_points, true, flags: JSON_THROW_ON_ERROR);
            $currentPromotion->setRelation('extraPoints', PromotionMultiplierWindow::hydrate($rules));
        }

        return [
            'business' => $business,
            'asOf' => CarbonImmutable::parse($snapshot->as_of),
            'currentPromotion' => $currentPromotion,
            'nextScheduled' => $this->promotion($snapshot->next_scheduled),
            'lastPromotion' => $this->promotion($snapshot->last_promotion),
            'appearancePrepared' => $business->pass_background_color !== null,
            'promotionPrepared' => (bool) $snapshot->promotion_prepared,
            'statistics' => $currentPromotion === null ? 'waiting' : ($statisticsUnavailable ? 'unavailable' : 'available'),
            'metrics' => $snapshot->metrics === null ? null : json_decode($snapshot->metrics, true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Selects context and optional independent scalar aggregates from the authoritative connection.
     *
     * @param  User  $actor  Server-resolved owner whose persisted Business relationship scopes every subquery.
     * @param  bool  $withMetrics  Whether to include aggregate storage, omitted only for fresh failure context.
     * @return stdClass|null One statement snapshot, or null when this owner has no Business.
     */
    private function snapshot(User $actor, bool $withMetrics): ?stdClass
    {
        $metrics = $withMetrics ? <<<'SQL'
            (SELECT json_build_object(
                'active_passes', (SELECT COUNT(DISTINCT v.customer_pass_id) FROM visits v
                    WHERE v.business_id = p.business_id AND v.promotion_id = p.id),
                'awarded_points', (SELECT COALESCE(SUM(v.awarded_points), 0) FROM visits v
                    WHERE v.business_id = p.business_id AND v.promotion_id = p.id),
                'unlocked_rewards', (SELECT COUNT(*) FROM reward_entitlements r
                    WHERE r.business_id = p.business_id AND r.promotion_id = p.id),
                'redeemed_rewards', (SELECT COUNT(*) FROM reward_entitlements r
                    WHERE r.business_id = p.business_id AND r.promotion_id = p.id AND r.redeemed_at IS NOT NULL)
            )::text FROM active_promotion p) AS metrics
            SQL : 'NULL::text AS metrics';

        return DB::selectOne(<<<SQL
            WITH summary_clock AS MATERIALIZED (SELECT clock_timestamp() AS instant),
            owned_business AS (SELECT * FROM businesses WHERE user_id = ?),
            phases AS (
                SELECT p.*, CASE
                    WHEN p.status = 'cancelled' THEN 'cancelled'
                    WHEN p.starts_at > c.instant THEN 'scheduled'
                    WHEN p.ends_at <= c.instant THEN 'ended'
                    ELSE 'active'
                END AS phase
                FROM promotions p JOIN owned_business b ON b.id = p.business_id CROSS JOIN summary_clock c
                WHERE p.status IN ('published', 'cancelled')
            ),
            active_promotion AS (SELECT * FROM phases WHERE phase = 'active' ORDER BY starts_at, id LIMIT 1)
            SELECT row_to_json(b)::text AS business, c.instant AS as_of,
                EXISTS(SELECT 1 FROM phases) AS promotion_prepared,
                (SELECT row_to_json(p)::text FROM active_promotion p) AS current_promotion,
                (SELECT row_to_json(p)::text FROM phases p WHERE phase = 'scheduled'
                    ORDER BY starts_at, id LIMIT 1) AS next_scheduled,
                (SELECT row_to_json(p)::text FROM phases p WHERE phase IN ('ended', 'cancelled')
                    ORDER BY LEAST(ends_at, cancelled_at) DESC, id DESC LIMIT 1) AS last_promotion,
                (SELECT COALESCE(json_agg(w ORDER BY w.weekday, w.start_time, w.id), '[]'::json)::text
                    FROM promotion_multiplier_windows w JOIN active_promotion p ON p.id = w.promotion_id) AS extra_points,
                {$metrics}
            FROM owned_business b CROSS JOIN summary_clock c
            SQL, [$actor->getKey()], false);
    }

    /**
     * Restores frozen Promotion terms using the existing immutable casts, without another database read.
     *
     * @param  string|null  $json  Owned Promotion attributes from the current statement snapshot.
     * @return Promotion|null Selected Promotion with derived phase, or null when that slot is empty.
     */
    private function promotion(?string $json): ?Promotion
    {
        return $json === null ? null : (new Promotion)->newFromBuilder(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }
}
