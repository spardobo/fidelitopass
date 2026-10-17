<?php

namespace App\Actions\Visits;

use App\Enums\PromotionStatus;
use App\Enums\VisitAcceptanceFailure;
use App\Exceptions\GoogleWalletSynchronizationException;
use App\Exceptions\VisitAcceptanceException;
use App\Integrations\GoogleWallet\GoogleWalletPassUpdater;
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

/** Owns one authorized, retry-safe Visit and atomic Reward unlock, including synchronous Wallet confirmation. */
class AcceptVisit
{
    private const int MAX_CREDENTIAL_LENGTH = 256;

    private const string TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/';

    private const string MANUAL_PATTERN = '/\A(?:[0-9]{5}|[A-Z0-9]{12})\z/';

    private const string RATE_LIMIT_PREFIX = 'pass-lookup:';

    /**
     * Receives the authoritative clock and existing same-connection Wallet integration.
     *
     * @param  DatabaseClock  $clock  PostgreSQL clock captured after required locks.
     * @param  GoogleWalletPassUpdater  $wallet  Confirms tentative absolute content without committing or logging.
     */
    public function __construct(
        private readonly DatabaseClock $clock,
        private readonly GoogleWalletPassUpdater $wallet,
    ) {}

    /**
     * Accepts one new Visit or returns immutable facts for an authorized replay.
     *
     * @param  User|null  $actor  Server-authenticated owner, or null.
     * @param  string  $method  Allowlisted token or manual credential method.
     * @param  string  $credential  Private issued token or Business-scoped manual code.
     * @param  string  $operationId  Global UUID identifying one intentional validation operation.
     * @param  int|null  $expectedPromotionId  Optional comparison with the selected offer, never mutation authority.
     * @return array{visit_id: int, operation_id: string, customer_pass_id: int, promotion_id: int, points_awarded: int, confirmed_at: string} Immutable committed Visit facts.
     *
     * @throws VisitAcceptanceException If authorization, input, eligibility or local persistence fails.
     * @throws GoogleWalletSynchronizationException If provider confirmation fails; propagated unchanged after logging.
     * @throws Throwable If native logging fails, including after a known commit.
     */
    public function handle(
        ?User $actor,
        string $method,
        #[SensitiveParameter] string $credential,
        string $operationId,
        ?int $expectedPromotionId = null,
    ): array {
        $businessId = null;
        $normalized = null;
        $providerConfirmed = false;
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
                &$businessId,
                &$providerConfirmed,
            ): array {
                return $this->processVisit(
                    $actor,
                    $method,
                    $normalized,
                    $safeOperationId,
                    $expectedPromotionId,
                    $businessId,
                    $providerConfirmed,
                );
            }, 1);
        } catch (GoogleWalletSynchronizationException $exception) {
            $exception->log([
                'actor_id' => $actor->id,
                'business_id' => $businessId,
                'operation_id' => $safeOperationId,
                'stage' => $exception->stage->value,
                'remote_outcome' => $exception->outcome->value,
                'provider_status' => $exception->providerStatus,
                'local_outcome' => 'not_accepted',
                'outcome' => 'not_accepted',
                'provider_confirmed' => false,
            ]);

            throw $exception;
        } catch (LogicException|Error $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $failure = VisitAcceptanceException::fromException($exception, $providerConfirmed);

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
     * @param  int|null  $businessId  Owning Business identity returned by reference for outcome correlation.
     * @param  bool  $providerConfirmed  Provider confirmation returned by reference independently of local commit.
     *
     * @param-out int $businessId Owning Business identity established before a successful return.
     *
     * @return array{visit: Visit, replayed: bool, unlocked: bool} Tentative facts requiring commit.
     *
     * @throws VisitAcceptanceException If authorization or persistence fails.
     * @throws GoogleWalletSynchronizationException If provider sync fails.
     */
    private function processVisit(
        User $actor,
        string $method,
        #[SensitiveParameter] string $normalized,
        string $operationId,
        ?int $expectedPromotionId,
        ?int &$businessId,
        bool &$providerConfirmed,
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

        // return existing facts immediately if the operation was already committed
        $existingVisit = Visit::query()->where('operation_id', $operationId)->first();
        if ($existingVisit !== null) {
            if (
                $existingVisit->business_id !== $business->id
                || $existingVisit->customer_pass_id !== $pass->id
            ) {
                throw new VisitAcceptanceException(VisitAcceptanceFailure::OperationConflict);
            }

            return ['visit' => $existingVisit, 'replayed' => true, 'unlocked' => false];
        }

        // capture one authoritative instant for all domain decisions and writes
        $operationAt = $this->clock->capture($business->timezone)->instant;

        // find the currently active promotion
        $promotion = $business->promotions()
            ->where('status', PromotionStatus::Published)
            ->whereNull('cancelled_at')
            ->whereRaw('starts_at <= ?::timestamptz AND ends_at > ?::timestamptz', [$operationAt, $operationAt])
            ->first();

        if ($promotion === null) {
            throw new VisitAcceptanceException(VisitAcceptanceFailure::PromotionUnavailable);
        }

        if (
            $expectedPromotionId !== null
            && $promotion->id !== $expectedPromotionId
        ) {
            throw new VisitAcceptanceException(VisitAcceptanceFailure::PromotionChanged);
        }

        // calculate current progress and assert the promotion is still open
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

        if (
            $earnedPoints >= $promotion->target_points
            || $hasReward
        ) {
            throw new VisitAcceptanceException(VisitAcceptanceFailure::PromotionCompleted);
        }

        // resolve the points multiplier for the current day and time
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

        // save the new immutable visit
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

        // create the reward entitlement if this visit completes the target
        $unlocked = $earnedPoints + $points >= $promotion->target_points;

        if ($unlocked) {
            $reward = new RewardEntitlement;
            $reward->business()->associate($business);
            $reward->customerPass()->associate($pass);
            $reward->promotion()->associate($promotion);
            $reward->setAttribute('unlocked_at', $operationAt);
            $reward->setCreatedAt($operationAt);
            $reward->setUpdatedAt($operationAt);

            if (! $reward->save()) {
                throw new VisitAcceptanceException(VisitAcceptanceFailure::SaveRejected);
            }
        }

        // synchronize the presentation with Google before local commit
        $this->wallet->update($business, $pass, $operationAt);
        $providerConfirmed = true;

        return ['visit' => $visit, 'replayed' => false, 'unlocked' => $unlocked];
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
     * @param  array{visit: Visit, replayed: bool, unlocked: bool}  $accepted  Committed facts and replay/unlock outcomes.
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
