<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use PDOException;
use SensitiveParameter;
use stdClass;

/** Reads private pass identity and current eligibility without accepting any counter operation. */
class CustomerPassLookup
{
    /**
     * Stores the database clock used for this read-only eligibility hint.
     *
     * @param  DatabaseClock  $clock  Authoritative PostgreSQL clock; later commands must capture their own locked instant.
     */
    public function __construct(private readonly DatabaseClock $clock) {}

    /**
     * Identifies a pass only within the currently authenticated, verified owner's Business.
     *
     * No domain records or provider objects are written. Failed credentials consume a shared owner limit;
     * successful lookups neither clear that limit nor authorize a later Visit or redemption.
     * Results are explicit nonsecret projections, never serialized models or private input.
     *
     * @param  string  $type  Allowlisted input method: token or manual.
     * @param  string  $credential  Private scan value or manually entered code, never retained in results or diagnostics.
     * @return array{status: 'forbidden'|'invalid'|'unavailable'}|array{status: 'throttled', retry_after: int}|array{
     *     status: 'found', customer_pass_id: int, checked_at: string,
     *     promotion: array{id: int, target_points: int, earned_points: int, reward_title: string, reward_status: string}|null,
     *     can_accept_visit: bool, can_redeem_reward: bool
     * } Safe read-only outcome. Unavailable never implies eligibility or confirmed absence.
     */
    public function lookup(string $type, #[SensitiveParameter] string $credential): array
    {
        $actor = Auth::user();
        if (! $actor instanceof User || ! $actor->hasVerifiedEmail()) {
            return ['status' => 'forbidden'];
        }

        $businessId = null;

        try {
            $business = $actor->business()->first();
            if ($business === null) {
                return ['status' => 'forbidden'];
            }

            $businessId = $business->id;
            $limitKey = 'pass-lookup:'.$actor->id;
            if (RateLimiter::tooManyAttempts($limitKey, max(1, (int) config('pass_lookup.max_invalid_attempts', 10)))) {
                Log::notice('customer_pass.lookup_throttled', [
                    'actor_id' => $actor->id,
                    'business_id' => $business->id,
                    'outcome' => 'rejected',
                    'reason' => 'invalid_attempt_limit',
                ]);

                return ['status' => 'throttled', 'retry_after' => RateLimiter::availableIn($limitKey)];
            }

            $value = $this->normalizeCredential($type, $credential);
            if ($value !== null) {
                $instant = $this->clock->capture($business->timezone)->instant;
                $rows = $this->readSnapshot($business, $type, $value, $instant);
                if (count($rows) > 1) {
                    // overlapping active terms must never produce an arbitrary affirmative hint
                    Log::error('customer_pass.lookup_unavailable', [
                        'actor_id' => $actor->id,
                        'business_id' => $business->id,
                        'outcome' => 'unavailable',
                        'reason' => 'ambiguous_active_promotion',
                    ]);

                    return ['status' => 'unavailable'];
                }
                if ($rows !== []) {
                    $row = $rows[0];
                    $promotion = null;
                    if ($row->promotion_id !== null) {
                        $promotion = [
                            'id' => (int) $row->promotion_id,
                            'target_points' => (int) $row->target_points,
                            'earned_points' => (int) $row->earned_points,
                            'reward_title' => (string) $row->reward_title,
                            'reward_status' => $row->reward_id === null ? 'none' : ($row->redeemed_at === null ? 'available' : 'redeemed'),
                        ];
                    }

                    return [
                        'status' => 'found',
                        'customer_pass_id' => (int) $row->customer_pass_id,
                        'checked_at' => $instant,
                        'promotion' => $promotion,
                        'can_accept_visit' => $promotion !== null
                            && $promotion['earned_points'] < $promotion['target_points']
                            && $promotion['reward_status'] === 'none',
                        'can_redeem_reward' => $promotion !== null && $promotion['reward_status'] === 'available',
                    ];
                }
            }

            RateLimiter::hit($limitKey, max(1, (int) config('pass_lookup.decay_seconds', 60)));

            Log::notice('customer_pass.lookup_rejected', [
                'actor_id' => $actor->id,
                'business_id' => $business->id,
                'outcome' => 'rejected',
                'reason' => 'invalid_credential',
            ]);

            return ['status' => 'invalid'];
        } catch (QueryException|PDOException $exception) {
            Log::error('customer_pass.lookup_unavailable', [
                'actor_id' => $actor->id,
                'business_id' => $businessId,
                'outcome' => 'unavailable',
                'reason' => 'authoritative_read_failed',
                'exception_class' => $exception::class,
                'source_file' => $exception->getFile(),
                'source_line' => $exception->getLine(),
                'exception_frames' => array_map(
                    static fn (array $frame): array => Arr::only($frame, ['file', 'line', 'class', 'function']),
                    $exception->getTrace(),
                ),
            ]);

            return ['status' => 'unavailable'];
        }
    }

    /**
     * Validates private input without changing scan-token bytes or retaining malformed values.
     *
     * @param  string  $type  Allowlisted input method.
     * @param  string  $credential  Untrusted private input; only manual entry permits separator normalization.
     * @return string|null Bound lookup value, or null for every invalid format or method.
     */
    private function normalizeCredential(string $type, #[SensitiveParameter] string $credential): ?string
    {
        if (strlen($credential) > 256) {
            return null;
        }
        if ($type === 'token') {
            return preg_match('/\A[a-f0-9]{64}\z/', $credential) === 1 ? hash('sha256', $credential) : null;
        }
        if ($type !== 'manual') {
            return null;
        }

        $code = strtoupper(str_replace([' ', '-'], '', $credential));

        return preg_match('/\A(?:[0-9]{5}|[A-Z0-9]{12})\z/', $code) === 1 ? $code : null;
    }

    /**
     * Reads pass, active terms, awarded totals and entitlement from one PostgreSQL statement snapshot.
     *
     * Every fact is scoped to the owner-resolved Business; Summary metrics are not consulted.
     * Returns at most two rows to detect ambiguous active terms instead of authorizing arbitrarily.
     *
     * @param  Business  $business  Business resolved from the authenticated owner, never from browser input.
     * @param  string  $type  Previously allowlisted credential method.
     * @param  string  $value  Validated private lookup code or token verifier, only used as a bound parameter.
     * @param  string  $instant  Current PostgreSQL instant captured once for this nonauthoritative hint.
     * @return array<int, stdClass> Native PostgreSQL result rows with the selected nonsecret columns.
     *
     * @throws QueryException If authoritative reads fail; the public boundary removes SQL and bindings.
     * @throws PDOException If the driver fails.
     */
    private function readSnapshot(Business $business, string $type, #[SensitiveParameter] string $value, string $instant): array
    {
        $column = $type === 'token' ? 'validation_token_hash' : 'manual_code';

        return DB::select(<<<SQL
            SELECT pass.id AS customer_pass_id, promotion.id AS promotion_id,
                   promotion.target_points, promotion.reward_title,
                   COALESCE((SELECT SUM(visit.awarded_points)
                             FROM visits AS visit
                             WHERE visit.business_id = pass.business_id
                               AND visit.customer_pass_id = pass.id
                               AND visit.promotion_id = promotion.id), 0) AS earned_points,
                   reward.id AS reward_id, reward.redeemed_at
            FROM customer_passes AS pass
            LEFT JOIN promotions AS promotion
                ON promotion.business_id = pass.business_id
                AND promotion.status = 'published'
                AND promotion.cancelled_at IS NULL
                AND promotion.starts_at <= CAST(? AS timestamptz)
                AND promotion.ends_at > CAST(? AS timestamptz)
            LEFT JOIN reward_entitlements AS reward
                ON reward.business_id = pass.business_id
                AND reward.customer_pass_id = pass.id
                AND reward.promotion_id = promotion.id
            WHERE pass.business_id = ? AND pass.{$column} = ?
            LIMIT 2
            SQL, [$instant, $instant, $business->id, $value]);
    }
}
