<?php

namespace App\Support;

use App\Enums\InspectionFailure;
use App\Exceptions\CustomerPassInspectionException;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PDOException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Read-only inspection of a private pass, always scoped to the
 * authenticated actor's Business.
 *
 * Performs no domain writes, never echoes the credential and emits
 * every operational event from its single public entry point.
 */
final class CustomerPassInspector
{
    private const int MAX_CREDENTIAL_LENGTH = 256;

    private const string TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/';

    private const string MANUAL_PATTERN = '/\A(?:[0-9]{5}|[A-Z0-9]{12})\z/';

    private const string RATE_LIMIT_PREFIX = 'pass-lookup:';

    /**
     * Receives the PostgreSQL clock used for the read-only hint.
     *
     * @param  DatabaseClock  $clock  Authoritative clock captured once per inspection.
     */
    public function __construct(private readonly DatabaseClock $clock) {}

    /**
     * Inspects a private credential within the actor's Business.
     *
     * The actor must be server-authenticated; the credential is never
     * retained in results or logs. Invalid attempts share one actor
     * window. A successful inspection is a nonauthoritative hint:
     * later commands must revalidate before accepting a Visit or a
     * redemption.
     *
     * @param  User|null  $actor  Server-authenticated owner, or null when unauthenticated.
     * @param  string  $method  Allowlisted credential method: token or manual.
     * @param  string  $credential  Private scan value or manual entry.
     * @return array{
     *     status: 'found',
     *     customer_pass_id: int,
     *     checked_at: string,
     *     promotion: array{
     *         id: int,
     *         target_points: int,
     *         earned_points: int,
     *         reward_title: string,
     *         reward_status: 'none'|'available'|'redeemed'
     *     }|null,
     *     can_accept_visit: bool,
     *     can_redeem_reward: bool
     * } Selected nonsecret facts; negative eligibility is still a success.
     *
     * @throws CustomerPassInspectionException If any validation, credential, throttle or authoritative-read check fails.
     * @throws Throwable If native logging or an unexpected collaborator failure interrupts inspection.
     */
    public function inspect(?User $actor, string $method, #[SensitiveParameter] string $credential): array
    {
        // reject unauthenticated or unverified actors before any other work
        if ($actor === null || ! $actor->hasVerifiedEmail()) {
            $this->reject(InspectionFailure::Forbidden);
        }

        $businessId = null;

        try {
            // resolve the actor's business; lookups are always scoped to it
            $business = $actor->business()->first();
            if ($business === null) {
                $this->reject(InspectionFailure::Forbidden);
            }

            $businessId = $business->id;

            // enforce the shared invalid-attempt window before touching the credential
            $rateLimitKey = self::RATE_LIMIT_PREFIX.$actor->id;
            $this->assertNotThrottled($rateLimitKey);

            // reject malformed credentials before reading the clock or the snapshot
            $normalized = $this->normalizeCredential($method, $credential);
            if ($normalized === null) {
                $this->recordInvalidAttempt($rateLimitKey);
                $this->reject(InspectionFailure::InvalidCredential);
            }

            // capture one authoritative instant for the whole snapshot
            $capturedAt = $this->clock->capture($business->timezone)->instant;
            $rows = $this->readSnapshot($business, $method, $normalized, $capturedAt);

            // reject ambiguous occupancy instead of choosing a pass arbitrarily
            if (count($rows) > 1) {
                $this->reject(InspectionFailure::AmbiguousActivePromotion);
            }

            // a well-formed credential resolving to no pass is a business rejection
            if ($rows === []) {
                $this->recordInvalidAttempt($rateLimitKey);
                $this->reject(InspectionFailure::InvalidCredential);
            }

            return $this->buildResult($rows[0], $capturedAt);
        } catch (QueryException|PDOException $exception) {
            // discard the secret-bearing cause while preserving the existing safe diagnostics
            $failure = new CustomerPassInspectionException(InspectionFailure::AuthoritativeReadFailed);
            $failure->log([
                'actor_id' => $actor->id,
                'business_id' => $businessId,
                'outcome' => 'unavailable',
                'previous_class' => $exception::class,
                'exception_class' => $exception::class,
                'source_file' => $exception->getFile(),
                'source_line' => $exception->getLine(),
                'exception_frames' => array_map(
                    static fn (array $frame): array => Arr::only($frame, ['file', 'line', 'class', 'function']),
                    $exception->getTrace(),
                ),
            ]);

            throw $failure;
        } catch (CustomerPassInspectionException $exception) {
            $exception->log([
                'actor_id' => $actor->id,
                'business_id' => $businessId,
                'outcome' => match ($exception->failure) {
                    InspectionFailure::Forbidden => 'forbidden',
                    InspectionFailure::InvalidCredential,
                    InspectionFailure::InvalidAttemptLimit => 'rejected',
                    InspectionFailure::AuthoritativeReadFailed,
                    InspectionFailure::AmbiguousActivePromotion => 'unavailable',
                },
            ]);

            throw $exception;
        }
    }

    // ------------------------------------------------------------------------
    // validations
    // ------------------------------------------------------------------------

    /**
     * Aborts the inspection with the given typed failure.
     *
     * @param  InspectionFailure  $failure  Typed reason propagated to the caller.
     * @param  int|null  $retryAfterSeconds  Optional Retry-After hint for throttled callers.
     *
     * @throws CustomerPassInspectionException Always.
     */
    private function reject(InspectionFailure $failure, ?int $retryAfterSeconds = null): never
    {
        $exception = new CustomerPassInspectionException($failure);
        $exception->retryAfterSeconds = $retryAfterSeconds;

        throw $exception;
    }

    /**
     * Rejects the inspection when the actor's invalid-attempt window is exhausted.
     *
     * @param  string  $rateLimitKey  Shared actor window key.
     *
     * @throws CustomerPassInspectionException If the window is exhausted.
     */
    private function assertNotThrottled(string $rateLimitKey): void
    {
        $maxAttempts = max(1, (int) config('pass_lookup.max_invalid_attempts', 10));

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            $this->reject(
                InspectionFailure::InvalidAttemptLimit,
                RateLimiter::availableIn($rateLimitKey),
            );
        }
    }

    /**
     * Records one invalid attempt inside the shared actor window.
     *
     * @param  string  $rateLimitKey  Shared actor window key.
     */
    private function recordInvalidAttempt(string $rateLimitKey): void
    {
        $decaySeconds = max(1, (int) config('pass_lookup.decay_seconds', 60));
        RateLimiter::hit($rateLimitKey, $decaySeconds);
    }

    /**
     * Converts a bound lookup value from the credential, or null when invalid.
     *
     * Tokens are hashed without changing their bytes; manual codes
     * keep only their outer trim. No internal rewriting is performed.
     *
     * @param  string  $method  Allowlisted credential method.
     * @param  string  $credential  Untrusted private input.
     * @return string|null Bound lookup value, or null for an invalid method or format.
     */
    private function normalizeCredential(string $method, #[SensitiveParameter] string $credential): ?string
    {
        // bound the raw input before any pattern check
        if (strlen($credential) > self::MAX_CREDENTIAL_LENGTH) {
            return null;
        }

        // hash the exact issued token string for its stored verifier
        if ($method === 'token') {
            return preg_match(self::TOKEN_PATTERN, $credential) === 1
                ? hash('sha256', $credential)
                : null;
        }

        // manual codes keep only their outer trim
        if ($method === 'manual') {
            $code = trim($credential);

            return preg_match(self::MANUAL_PATTERN, $code) === 1 ? $code : null;
        }

        return null;
    }

    // ------------------------------------------------------------------------
    // business logic
    // ------------------------------------------------------------------------

    /**
     * Reads every pass row matching the credential from one PostgreSQL statement snapshot.
     *
     * Returns all matches so the caller can distinguish "no pass" from
     * "ambiguous occupancy"; no LIMIT hides a second active Promotion.
     *
     * @param  Business  $business  Business resolved from the authenticated actor.
     * @param  string  $method  Previously allowlisted credential method.
     * @param  string  $value  Canonical private lookup value, only used as a bound parameter.
     * @param  string  $capturedAt  Current PostgreSQL instant captured once for this hint.
     * @return array<int, stdClass> Selected nonsecret columns, possibly empty.
     *
     * @throws QueryException If the authoritative read fails.
     * @throws PDOException If the driver fails.
     */
    private function readSnapshot(Business $business, string $method, #[SensitiveParameter] string $value, string $capturedAt): array
    {
        $column = $method === 'token' ? 'validation_token_hash' : 'manual_code';

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
            SQL, [$capturedAt, $capturedAt, $business->id, $value]);
    }

    /**
     * Projects one snapshot row into the fixed public shape.
     *
     * @param  stdClass  $row  Selected pass facts with zero or one active Promotion.
     * @param  string  $capturedAt  PostgreSQL instant captured for this inspection.
     * @return array{
     *     status: 'found',
     *     customer_pass_id: int,
     *     checked_at: string,
     *     promotion: array{
     *         id: int,
     *         target_points: int,
     *         earned_points: int,
     *         reward_title: string,
     *         reward_status: 'none'|'available'|'redeemed'
     *     }|null,
     *     can_accept_visit: bool,
     *     can_redeem_reward: bool
     * }
     */
    private function buildResult(stdClass $row, string $capturedAt): array
    {
        // build the promotion projection once; eligibility flags derive from it
        $promotion = $row->promotion_id === null ? null : [
            'id' => (int) $row->promotion_id,
            'target_points' => (int) $row->target_points,
            'earned_points' => (int) $row->earned_points,
            'reward_title' => (string) $row->reward_title,
            'reward_status' => $row->reward_id === null
                ? 'none'
                : ($row->redeemed_at === null ? 'available' : 'redeemed'),
        ];

        return [
            'status' => 'found',
            'customer_pass_id' => (int) $row->customer_pass_id,
            'checked_at' => $capturedAt,
            'promotion' => $promotion,
            'can_accept_visit' => $promotion !== null
                && $promotion['earned_points'] < $promotion['target_points']
                && $promotion['reward_status'] === 'none',
            'can_redeem_reward' => $promotion !== null
                && $promotion['reward_status'] === 'available',
        ];
    }
}
