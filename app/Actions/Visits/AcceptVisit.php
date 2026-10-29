<?php

namespace App\Actions\Visits;

use App\Enums\FailureOutcome;
use App\Enums\PromotionStatus;
use App\Enums\VisitAcceptanceFailure;
use App\Exceptions\VisitAcceptanceException;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Models\RewardEntitlement;
use App\Models\User;
use App\Models\Visit;
use App\Support\DatabaseClock;
use Error;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Owns one authorized Visit, atomic Reward unlock and optional redemption with local transaction integrity. */
class AcceptVisit
{
    private const int MAX_CREDENTIAL_LENGTH = 256;

    private const string TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/';

    private const string MANUAL_PATTERN = '/\A(?:[0-9]{5}|[A-Z0-9]{12})\z/';

    private const string RATE_LIMIT_PREFIX = 'pass-lookup:';

    /**
     * Receives the authoritative operation clock.
     *
     * @param  DatabaseClock  $clock  PostgreSQL clock captured after required locks.
     */
    public function __construct(
        private readonly DatabaseClock $clock,
    ) {}

    /**
     * Accepts one new Visit or returns immutable facts for an authorized replay.
     *
     * @param  User|null  $actor  Server-authenticated owner, or null.
     * @param  string  $method  Allowlisted token or manual credential method.
     * @param  string  $credential  Private issued token or Business-scoped manual code.
     * @param  string  $operationId  Global UUID identifying one intentional validation operation.
     * @param  int|null  $expectedPromotionId  Optional comparison with the selected offer, never mutation authority.
     * @param  bool  $redeemReward  Explicit compound intent; success additionally proves the original entitlement is redeemed.
     * @return array{visit_id: int, operation_id: string, customer_pass_id: int, promotion_id: int, points_awarded: int, confirmed_at: string} Immutable committed Visit facts.
     *
     * @throws VisitAcceptanceException If authorization, input, eligibility or local persistence fails.
     * @throws Throwable If native logging fails, including after a known commit.
     */
    public function handle(
        ?User $actor,
        string $method,
        #[SensitiveParameter] string $credential,
        string $operationId,
        ?int $expectedPromotionId = null,
        bool $redeemReward = false,
    ): array {
        $businessId = null;
        $normalized = null;
        $localOutcome = FailureOutcome::Definitive;
        $safeOperationId = Str::isUuid($operationId) ? strtolower($operationId) : null;

        try {
            // reject unauthenticated actors before any other work
            if (
                $actor === null
                || ! $actor->exists
                || ! $actor->hasVerifiedEmail()
            ) {
                throw new VisitAcceptanceException(VisitAcceptanceFailure::Forbidden);
            }

            // refuse nested execution; this action owns its transaction boundary
            if (DB::transactionLevel() !== 0) {
                throw new VisitAcceptanceException(VisitAcceptanceFailure::TransactionActive);
            }

            // require a valid operation identity before credential work
            if ($safeOperationId === null) {
                throw new VisitAcceptanceException(VisitAcceptanceFailure::InvalidOperation);
            }

            // enforce the shared invalid-attempt window before touching the credential
            $rateLimitKey = self::RATE_LIMIT_PREFIX.$actor->id;
            $maxAttempts = max(1, (int) config('pass_lookup.max_invalid_attempts', 10));

            if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
                throw new VisitAcceptanceException(
                    VisitAcceptanceFailure::InvalidAttemptLimit,
                    retryAfterSeconds: RateLimiter::availableIn($rateLimitKey),
                );
            }

            // reject malformed credentials before reading the clock or the database
            $normalized = $this->normalizeCredential($method, $credential);
            if ($normalized === null) {
                $this->recordInvalidAttempt($rateLimitKey);

                throw new VisitAcceptanceException(VisitAcceptanceFailure::InvalidCredential);
            }

            // preserve operation evidence outside the single non-retrying transaction
            $accepted = DB::transaction(function () use (
                $actor,
                $method,
                $normalized,
                $safeOperationId,
                $expectedPromotionId,
                $redeemReward,
                &$businessId,
                &$localOutcome,
            ): array {
                $accepted = $this->processVisit(
                    $actor,
                    $method,
                    $normalized,
                    $safeOperationId,
                    $expectedPromotionId,
                    $redeemReward,
                    $businessId,
                );

                // callback completion reaches the native commit phase; failures cannot prove rollback
                $localOutcome = FailureOutcome::Unknown;

                return $accepted;
            }, 1);
        } catch (LogicException|Error $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $failure = VisitAcceptanceException::fromException($exception, $localOutcome);

            if (
                $failure->failure === VisitAcceptanceFailure::InvalidCredential
                && $normalized !== null
            ) {
                $this->recordInvalidAttempt($rateLimitKey);
            }

            $failure->log([
                'actor_id' => $actor?->id,
                'business_id' => $businessId,
                'operation_id' => $safeOperationId,
            ]);

            throw $failure;
        }

        // record the success events only after a confirmed commit
        $this->logSuccess($accepted, $actor, $businessId);

        return $this->buildResult($accepted['visit']);
    }

    /**
     * Executes the core Visit logic inside the owning database transaction.
     *
     * @param  User  $actor  Server-authenticated owner.
     * @param  string  $method  Allowlisted credential method.
     * @param  string  $normalized  Private canonical manual code or token hash used only for scoped lookup.
     * @param  string  $operationId  Validated operation UUID.
     * @param  int|null  $expectedPromotionId  Selected offer compared only for a new operation after authorized replay.
     * @param  bool  $redeemReward  Requires a redeemed original entitlement without upgrading an existing Visit.
     * @param  int|null  $businessId  Owning Business identity returned by reference for outcome correlation.
     *
     * @param-out int $businessId Owning Business identity established before a successful return.
     *
     * @return array{visit: Visit, replayed: bool, unlocked: bool, redeemed: bool} Tentative facts requiring commit.
     *
     * @throws VisitAcceptanceException If authorization or persistence fails.
     */
    private function processVisit(
        User $actor,
        string $method,
        #[SensitiveParameter] string $normalized,
        string $operationId,
        ?int $expectedPromotionId,
        bool $redeemReward,
        ?int &$businessId,
    ): array {
        // lock the business to serialize concurrent publications and visits
        $business = $actor->business()->lockForUpdate()->first();
        if ($business === null) {
            throw new VisitAcceptanceException(VisitAcceptanceFailure::Forbidden);
        }

        $businessId = $business->id;

        // find and lock the customer pass matching the credential
        $pass = $business->customerPasses()
            ->where($method === 'token' ? 'validation_token_hash' : 'manual_code', $normalized)
            ->lockForUpdate()
            ->first();

        if ($pass === null) {
            throw new VisitAcceptanceException(VisitAcceptanceFailure::InvalidCredential);
        }

        // an authorized replay returns its original facts before current promotion checks
        $existingVisit = Visit::query()->where('operation_id', $operationId)->first();
        if ($existingVisit !== null) {
            return $this->replayVisit($existingVisit, $business, $pass, $redeemReward);
        }

        // evaluate one new Visit using the authoritative post-lock instant
        $operationAt = $this->clock->capture($business->timezone)->instant;
        $promotion = $this->requireActivePromotion($business, $operationAt, $expectedPromotionId);
        $evaluation = $this->evaluateVisit($business, $pass, $promotion, $operationAt, $redeemReward);

        // persist the Visit and optional entitlement as one tentative domain operation
        $visit = $this->storeVisit($business, $pass, $promotion, $actor, $operationId, $operationAt, $evaluation['points']);

        if ($evaluation['unlocked']) {
            $this->unlockReward($business, $pass, $promotion, $actor, $operationAt, $redeemReward);
        }

        return ['visit' => $visit, 'replayed' => false, 'unlocked' => $evaluation['unlocked'], 'redeemed' => $redeemReward];
    }

    /**
     * Replays only the original authorized operation, optionally requiring its original redemption.
     *
     * @param  Visit  $visit  Previously committed operation with immutable facts.
     * @param  Business  $business  Locked owning Business.
     * @param  CustomerPass  $pass  Locked credential-matched pass.
     * @param  bool  $redeemReward  Whether the original compound operation must already be redeemed.
     * @return array{visit: Visit, replayed: bool, unlocked: bool, redeemed: bool} Original replay facts without new domain effects.
     *
     * @throws VisitAcceptanceException When the operation belongs elsewhere or redemption is absent.
     */
    private function replayVisit(Visit $visit, Business $business, CustomerPass $pass, bool $redeemReward): array
    {
        if (
            $visit->business_id !== $business->id
            || $visit->customer_pass_id !== $pass->id
        ) {
            throw new VisitAcceptanceException(VisitAcceptanceFailure::OperationConflict);
        }

        if ($redeemReward) {
            $originalReward = RewardEntitlement::query()
                ->where('business_id', $business->id)
                ->where('customer_pass_id', $pass->id)
                ->where('promotion_id', $visit->promotion_id)
                ->lockForUpdate()
                ->first();

            if ($originalReward === null || $originalReward->redeemed_at === null) {
                throw new VisitAcceptanceException(VisitAcceptanceFailure::RewardNotEarned);
            }
        }

        return ['visit' => $visit, 'replayed' => true, 'unlocked' => false, 'redeemed' => false];
    }

    /**
     * Selects the published active Promotion and checks the caller's expected identity.
     *
     * @param  Business  $business  Locked owner that serializes publication with acceptance.
     * @param  string  $operationAt  Post-lock PostgreSQL instant for inclusive start and exclusive end.
     * @param  int|null  $expectedPromotionId  Non-authoritative comparison against the selected Promotion.
     * @return Promotion Original active published Promotion.
     *
     * @throws VisitAcceptanceException When no Promotion is active or the selection changed.
     */
    private function requireActivePromotion(Business $business, string $operationAt, ?int $expectedPromotionId): Promotion
    {
        $promotion = $business->promotions()
            ->where('status', PromotionStatus::Published)
            ->whereNull('cancelled_at')
            ->whereRaw('starts_at <= ?::timestamptz AND ends_at > ?::timestamptz', [$operationAt, $operationAt])
            ->first();

        if ($promotion === null) {
            throw new VisitAcceptanceException(VisitAcceptanceFailure::PromotionUnavailable);
        }

        if ($expectedPromotionId !== null && $promotion->id !== $expectedPromotionId) {
            throw new VisitAcceptanceException(VisitAcceptanceFailure::PromotionChanged);
        }

        return $promotion;
    }

    /**
     * Evaluates existing progress, completion and the applicable frozen point multiplier for one new Visit.
     *
     * @param  Business  $business  Locked owning Business.
     * @param  CustomerPass  $pass  Locked customer pass.
     * @param  Promotion  $promotion  Active published Promotion with frozen point rules.
     * @param  string  $operationAt  Shared post-lock PostgreSQL instant for the multiplier window.
     * @param  bool  $redeemReward  Whether this Visit must also reach the reward target.
     * @return array{points: int, unlocked: bool} New awarded points and whether this Visit unlocks the reward.
     *
     * @throws VisitAcceptanceException When the Promotion is complete or redemption cannot yet be earned.
     */
    private function evaluateVisit(
        Business $business,
        CustomerPass $pass,
        Promotion $promotion,
        string $operationAt,
        bool $redeemReward,
    ): array {
        // reject already completed terms using immutable Visit totals and the persisted entitlement
        $earnedPoints = (int) Visit::query()
            ->where('business_id', $business->id)
            ->where('customer_pass_id', $pass->id)
            ->where('promotion_id', $promotion->id)
            ->sum('awarded_points');

        $hasReward = RewardEntitlement::query()
            ->where('business_id', $business->id)
            ->where('customer_pass_id', $pass->id)
            ->where('promotion_id', $promotion->id)
            ->exists();

        if ($earnedPoints >= $promotion->target_points || $hasReward) {
            throw new VisitAcceptanceException(VisitAcceptanceFailure::PromotionCompleted);
        }

        // resolve exactly one frozen multiplier window at the shared operation instant
        $window = $promotion->extraPoints()
            ->whereRaw('weekday = EXTRACT(ISODOW FROM (?::timestamptz AT TIME ZONE ?))', [$operationAt, $promotion->timezone_snapshot])
            ->where(function ($query) use ($operationAt, $promotion): void {
                $query->whereNull('start_time')
                    ->orWhere(function ($query) use ($operationAt, $promotion): void {
                        $query->whereRaw('start_time <= (?::timestamptz AT TIME ZONE ?)::time', [$operationAt, $promotion->timezone_snapshot])
                            ->whereRaw('end_time > (?::timestamptz AT TIME ZONE ?)::time', [$operationAt, $promotion->timezone_snapshot]);
                    });
            })
            ->first();

        $points = $window->multiplier ?? 1;
        $unlocked = $earnedPoints + $points >= $promotion->target_points;

        if ($redeemReward && ! $unlocked) {
            throw new VisitAcceptanceException(VisitAcceptanceFailure::RewardNotEarned);
        }

        return ['points' => $points, 'unlocked' => $unlocked];
    }

    /**
     * Records the accepted Visit with its stable operation identity and post-lock PostgreSQL timestamps.
     *
     * @param  Business  $business  Locked owner of the accepted Visit.
     * @param  CustomerPass  $pass  Locked pass receiving points.
     * @param  Promotion  $promotion  Active Promotion awarding the points.
     * @param  User  $actor  Verified owner confirming the Visit.
     * @param  string  $operationId  Validated immutable operation UUID.
     * @param  string  $operationAt  Authoritative operation timestamp.
     * @param  int  $points  Awarded points from the original multiplier evaluation.
     * @return Visit Tentatively saved immutable Visit.
     *
     * @throws VisitAcceptanceException When the Visit cannot be persisted.
     */
    private function storeVisit(
        Business $business,
        CustomerPass $pass,
        Promotion $promotion,
        User $actor,
        string $operationId,
        string $operationAt,
        int $points,
    ): Visit {
        $visit = new Visit;
        $visit->business()->associate($business);
        $visit->customerPass()->associate($pass);
        $visit->promotion()->associate($promotion);
        $visit->confirmedBy()->associate($actor);
        $visit->operation_id = $operationId;
        $visit->awarded_points = $points;
        $visit->setAttribute('confirmed_at', $operationAt);
        $visit->setCreatedAt($operationAt);
        $visit->setUpdatedAt($operationAt);

        if (! $visit->save()) {
            throw new VisitAcceptanceException(VisitAcceptanceFailure::SaveRejected);
        }

        return $visit;
    }

    /**
     * Creates the entitlement unlocked by this Visit and optionally redeems it atomically.
     *
     * @param  Business  $business  Locked owner of this Visit.
     * @param  CustomerPass  $pass  Locked customer identity.
     * @param  Promotion  $promotion  Published offer that has just met its target.
     * @param  User  $actor  Owner confirming the Visit and optional redemption.
     * @param  string  $operationAt  Shared post-lock PostgreSQL instant.
     * @param  bool  $redeemReward  Whether redemption was explicitly confirmed with the Visit.
     *
     * @throws VisitAcceptanceException When saving the entitlement is rejected.
     */
    private function unlockReward(
        Business $business,
        CustomerPass $pass,
        Promotion $promotion,
        User $actor,
        string $operationAt,
        bool $redeemReward,
    ): void {
        $reward = new RewardEntitlement;
        $reward->business()->associate($business);
        $reward->customerPass()->associate($pass);
        $reward->promotion()->associate($promotion);
        $reward->setAttribute('unlocked_at', $operationAt);

        if ($redeemReward) {
            $reward->setAttribute('redeemed_at', $operationAt);
            $reward->redeemedBy()->associate($actor);
        }

        $reward->setCreatedAt($operationAt);
        $reward->setUpdatedAt($operationAt);

        if (! $reward->save()) {
            throw new VisitAcceptanceException(VisitAcceptanceFailure::SaveRejected);
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
     * Normalizes only manual outer whitespace and hashes exact issued token bytes.
     *
     * @param  string  $method  Allowlisted token or manual method.
     * @param  string  $credential  Untrusted private credential.
     * @return string|null Bound lookup value, or null for malformed input.
     */
    private function normalizeCredential(string $method, #[SensitiveParameter] string $credential): ?string
    {
        // bound the raw input before any pattern check
        if (strlen($credential) > self::MAX_CREDENTIAL_LENGTH) {
            return null;
        }

        // hash the exact issued token string for its stored verifier
        if ($method === 'token') {
            return preg_match(self::TOKEN_PATTERN, $credential) === 1 ? hash('sha256', $credential) : null;
        }

        // manual codes keep only their outer trim
        if ($method === 'manual') {
            $code = trim($credential);

            return preg_match(self::MANUAL_PATTERN, $code) === 1 ? $code : null;
        }

        return null;
    }

    /**
     * Emits the success events for the committed operation.
     *
     * @param  array{visit: Visit, replayed: bool, unlocked: bool, redeemed: bool}  $accepted  Committed facts and new effects.
     * @param  User  $actor  Server-authenticated owner whose command completed.
     * @param  int|null  $businessId  Owning Business identity captured inside the transaction.
     */
    private function logSuccess(array $accepted, User $actor, ?int $businessId): void
    {
        $visit = $accepted['visit'];
        $context = [
            'actor_id' => $actor->id,
            'business_id' => $businessId,
            'customer_pass_id' => $visit->customer_pass_id,
            'promotion_id' => $visit->promotion_id,
            'operation_id' => $visit->operation_id,
            'visit_id' => $visit->id,
            'confirmed_at' => $visit->confirmed_at->utc()->toISOString(),
            'outcome' => $accepted['replayed'] ? 'replayed' : 'accepted',
        ];

        Log::info($accepted['replayed'] ? 'visit.replayed' : 'visit.accepted', $context);

        if ($accepted['unlocked']) {
            Log::info('reward.unlocked', [...$context, 'outcome' => 'unlocked']);
        }

        if ($accepted['redeemed']) {
            Log::info('reward.redeemed', [...$context, 'outcome' => 'redeemed']);
        }
    }

    /**
     * Projects the committed Visit into the fixed public shape.
     *
     * @param  Visit  $visit  Persisted immutable facts for the accepted or replayed operation.
     * @return array{visit_id: int, operation_id: string, customer_pass_id: int, promotion_id: int, points_awarded: int, confirmed_at: string} Original Visit facts with a stable UTC microsecond instant.
     */
    private function buildResult(Visit $visit): array
    {
        return [
            'visit_id' => $visit->id,
            'operation_id' => $visit->operation_id,
            'customer_pass_id' => $visit->customer_pass_id,
            'promotion_id' => $visit->promotion_id,
            'points_awarded' => $visit->awarded_points,
            'confirmed_at' => $visit->confirmed_at->utc()->toISOString(),
        ];
    }
}
