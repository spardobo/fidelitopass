<?php

namespace App\Actions\Rewards;

use App\Enums\PromotionStatus;
use App\Enums\RewardRedemptionFailure;
use App\Exceptions\GoogleWalletSynchronizationException;
use App\Exceptions\RewardRedemptionException;
use App\Integrations\GoogleWallet\GoogleWalletPassUpdater;
use App\Models\Promotion;
use App\Models\RewardEntitlement;
use App\Models\User;
use App\Support\DatabaseClock;
use Error;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Owns one final redemption and synchronous Wallet confirmation in a non-retrying transaction. */
class RedeemReward
{
    /**
     * Receives the post-lock clock and operation-neutral Wallet integration.
     *
     * @param  DatabaseClock  $clock  Authoritative PostgreSQL wall clock.
     * @param  GoogleWalletPassUpdater  $wallet  Provider confirmation without transaction ownership.
     */
    public function __construct(
        private readonly DatabaseClock $clock,
        private readonly GoogleWalletPassUpdater $wallet,
    ) {}

    /**
     * Redeems the explicit original entitlement or returns its authorized immutable final facts.
     *
     * @param  User|null  $actor  Server-authenticated owner.
     * @param  string  $method  Token or manual credential method.
     * @param  string  $credential  Private current credential.
     * @param  int  $entitlementId  Original selected entitlement, never authority.
     * @param  int  $promotionId  Original selected Promotion, never the currently active replacement.
     * @return array{reward_entitlement_id: int, customer_pass_id: int, promotion_id: int, redeemed_at: string, redeemed_by_user_id: int} Committed final facts.
     *
     * @throws RewardRedemptionException When authorization, eligibility or persistence fails.
     * @throws GoogleWalletSynchronizationException When Google confirmation fails.
     * @throws Throwable When native logging or a programmer contract fails.
     */
    public function handle(
        ?User $actor,
        string $method,
        #[SensitiveParameter] string $credential,
        int $entitlementId,
        int $promotionId,
    ): array {
        $businessId = null;
        $normalized = null;
        $providerConfirmed = false;

        try {
            if (
                $actor === null
                || ! $actor->exists
                || ! $actor->hasVerifiedEmail()
            ) {
                throw new RewardRedemptionException(RewardRedemptionFailure::Forbidden);
            }

            if (DB::transactionLevel() !== 0) {
                throw new RewardRedemptionException(RewardRedemptionFailure::TransactionActive);
            }

            // share the existing credential limit with identification and visit acceptance
            $rateLimitKey = 'pass-lookup:'.$actor->id;
            $maxAttempts = max(1, (int) config('pass_lookup.max_invalid_attempts', 10));

            if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
                throw new RewardRedemptionException(
                    RewardRedemptionFailure::InvalidAttemptLimit,
                    retryAfterSeconds: RateLimiter::availableIn($rateLimitKey),
                );
            }

            // only a valid private credential can reach the transaction
            $normalized = $this->normalizeCredential($method, $credential);
            if ($normalized === null) {
                RateLimiter::hit($rateLimitKey, max(1, (int) config('pass_lookup.decay_seconds', 60)));
                throw new RewardRedemptionException(RewardRedemptionFailure::InvalidCredential);
            }

            // serialize the original target and confirm the absolute Wallet state before commit
            $result = DB::transaction(function () use (
                $actor,
                $method,
                $normalized,
                $entitlementId,
                $promotionId,
                &$businessId,
                &$providerConfirmed,
            ): array {
                return $this->redeemReward(
                    $actor,
                    $method,
                    $normalized,
                    $entitlementId,
                    $promotionId,
                    $businessId,
                    $providerConfirmed,
                );
            }, attempts: 1);
        } catch (GoogleWalletSynchronizationException $exception) {
            $exception->log([
                'actor_id' => $actor->id,
                'business_id' => $businessId,
                'reward_entitlement_id' => $entitlementId,
                'promotion_id' => $promotionId,
                'stage' => $exception->stage->value,
                'remote_outcome' => $exception->outcome->value,
                'provider_status' => $exception->providerStatus,
                'provider_confirmed' => false,
                'local_outcome' => 'not_redeemed',
                'outcome' => 'not_redeemed',
            ]);

            throw $exception;
        } catch (LogicException|Error $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $failure = RewardRedemptionException::fromException($exception, $providerConfirmed);

            if (
                $failure->failure === RewardRedemptionFailure::InvalidCredential
                && $normalized !== null
            ) {
                RateLimiter::hit($rateLimitKey, max(1, (int) config('pass_lookup.decay_seconds', 60)));
            }

            $failure->log([
                'actor_id' => $actor?->id,
                'business_id' => $businessId,
                'reward_entitlement_id' => $entitlementId,
                'promotion_id' => $promotionId,
            ]);

            throw $failure;
        }

        $reward = $result['reward'];
        $facts = [
            'reward_entitlement_id' => $reward->id,
            'customer_pass_id' => $reward->customer_pass_id,
            'promotion_id' => $reward->promotion_id,
            'redeemed_at' => $reward->redeemed_at->utc()->toISOString(),
            'redeemed_by_user_id' => $reward->redeemed_by_user_id,
        ];

        Log::info($result['replayed'] ? 'reward.redemption_replayed' : 'reward.redeemed', [
            ...$facts,
            'business_id' => $businessId,
            'actor_id' => $actor->id,
            'outcome' => $result['replayed'] ? 'replayed' : 'redeemed',
        ]);

        return $facts;
    }

    /**
     * Resolves the locked original entitlement, permits replay and commits a new redemption only once.
     *
     * The caller owns this non-retrying transaction; no later Promotion replaces the selected target.
     *
     * @param  User  $actor  Verified Business owner.
     * @param  string  $method  Validated credential method.
     * @param  string  $normalized  Private verifier or normalized manual code.
     * @param  int  $entitlementId  Original entitlement identity.
     * @param  int  $promotionId  Original Promotion identity.
     * @param  int|null  $businessId  Owning Business ID for outer failure reporting.
     * @param  bool  $providerConfirmed  Whether the remote update completed before any local failure.
     *
     * @param-out int $businessId Business ID resolved under its row lock.
     *
     * @return array{reward: RewardEntitlement, replayed: bool} Tentative redemption or committed replay.
     *
     * @throws RewardRedemptionException When ownership, eligibility or local persistence rejects redemption.
     * @throws GoogleWalletSynchronizationException When remote confirmation fails.
     */
    private function redeemReward(
        User $actor,
        string $method,
        #[SensitiveParameter] string $normalized,
        int $entitlementId,
        int $promotionId,
        ?int &$businessId,
        bool &$providerConfirmed,
    ): array {
        // lock the owning Business and original customer identity
        $business = $actor->business()->lockForUpdate()->first();
        if ($business === null) {
            throw new RewardRedemptionException(RewardRedemptionFailure::Forbidden);
        }

        $businessId = $business->id;
        $pass = $business->customerPasses()
            ->where($method === 'token' ? 'validation_token_hash' : 'manual_code', $normalized)
            ->lockForUpdate()
            ->first();

        if ($pass === null) {
            throw new RewardRedemptionException(RewardRedemptionFailure::InvalidCredential);
        }

        // resolve only the originally selected promotion and entitlement
        $promotion = $business->promotions()
            ->whereKey($promotionId)
            ->lockForUpdate()
            ->first();
        $reward = RewardEntitlement::query()
            ->where('business_id', $business->id)
            ->where('customer_pass_id', $pass->id)
            ->where('promotion_id', $promotionId)
            ->whereKey($entitlementId)
            ->lockForUpdate()
            ->first();

        if ($promotion === null || $reward === null) {
            throw new RewardRedemptionException(RewardRedemptionFailure::TargetUnavailable);
        }

        // a committed redemption is replayable even after the original promotion expires
        if ($reward->redeemed_at !== null) {
            return ['reward' => $reward, 'replayed' => true];
        }

        // capture PostgreSQL time only after every required lock
        $operationAt = $this->clock->capture($business->timezone)->instant;

        $this->requireRedeemablePromotion($promotion, $operationAt);

        // persist final redemption and confirm the same Google Object before commit
        $reward->redeemedBy()->associate($actor);
        $reward->setAttribute('redeemed_at', $operationAt);
        $reward->setUpdatedAt($operationAt);

        if (! $reward->save()) {
            throw new RewardRedemptionException(RewardRedemptionFailure::SaveRejected);
        }

        $this->wallet->update($business, $pass, $operationAt);
        $providerConfirmed = true;

        return ['reward' => $reward, 'replayed' => false];
    }

    /**
     * Rejects a new redemption when its original Promotion is cancelled, expired or not yet active.
     *
     * Completed redemptions bypass this check through the earlier authorized replay branch.
     *
     * @param  Promotion  $promotion  Original Promotion selected and locked by the transaction.
     * @param  string  $operationAt  Post-lock PostgreSQL instant of the attempted redemption.
     *
     * @throws RewardRedemptionException When original terms no longer permit new redemption.
     */
    private function requireRedeemablePromotion(Promotion $promotion, string $operationAt): void
    {
        if (
            $promotion->cancelled_at !== null
            || $promotion->status === PromotionStatus::Cancelled
        ) {
            throw new RewardRedemptionException(RewardRedemptionFailure::Cancelled);
        }

        if (
            $promotion->ends_at !== null
            && $promotion->ends_at->lessThanOrEqualTo($operationAt)
        ) {
            throw new RewardRedemptionException(RewardRedemptionFailure::Expired);
        }

        if (
            $promotion->status !== PromotionStatus::Published
            || $promotion->starts_at === null
            || $promotion->starts_at->greaterThan($operationAt)
        ) {
            throw new RewardRedemptionException(RewardRedemptionFailure::TargetUnavailable);
        }
    }

    /**
     * Normalizes only manual outer whitespace and hashes exact issued token bytes.
     *
     * @param  string  $method  Allowlisted credential method.
     * @param  string  $credential  Untrusted private input.
     * @return string|null Bound verifier or code, or null for malformed input.
     */
    private function normalizeCredential(string $method, #[SensitiveParameter] string $credential): ?string
    {
        if (strlen($credential) > 256) {
            return null;
        }

        if ($method === 'token') {
            return preg_match('/\A[a-f0-9]{64}\z/', $credential) === 1 ? hash('sha256', $credential) : null;
        }

        if ($method === 'manual') {
            $code = trim($credential);

            return preg_match('/\A(?:[0-9]{5}|[A-Z0-9]{12})\z/', $code) === 1 ? $code : null;
        }

        return null;
    }
}
