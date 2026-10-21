<?php

namespace App\Support;

use App\Exceptions\CustomerPassFindingException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use SensitiveParameter;

/** Reads the original entitlement under current credential and tenant authority, without mutation. */
final class RewardRedemptionReceipt
{
    /**
     * Receives the existing current-credential authorization boundary.
     *
     * @param  CustomerPassFinder  $finder  Owner-scoped private credential lookup.
     */
    public function __construct(private readonly CustomerPassFinder $finder) {}

    /**
     * Reads one original Promotion entitlement, optionally constrained to its selected identity.
     *
     * A missing or unredeemed receipt never proves rollback of a prior submission.
     *
     * @param  User|null  $actor  Server-authenticated owner.
     * @param  string  $method  Token or manual credential method.
     * @param  string  $credential  Current private credential.
     * @param  int  $promotionId  Explicit original Promotion, not current active selection.
     * @param  int|null  $entitlementId  Explicit entitlement for reconciliation; null only for initial discovery.
     * @return array{reward_entitlement_id: int, customer_pass_id: int, promotion_id: int, redeemed_at: string|null, redeemed_by_user_id: int|null, can_redeem_reward: bool, promotion: array{reward_title: string, reward_description: string|null, earned_points: int, target_points: int, deadline: string}}|null Scoped original terms and current finality.
     *
     * @throws CustomerPassFindingException When authority or the additional read fails.
     */
    public function find(
        ?User $actor,
        string $method,
        #[SensitiveParameter] string $credential,
        int $promotionId,
        ?int $entitlementId = null,
    ): ?array {
        $found = $this->finder->find($actor, $method, $credential);
        $column = $method === 'token' ? 'validation_token_hash' : 'manual_code';
        $value = $method === 'token' ? hash('sha256', $credential) : trim($credential);

        try {
            $row = DB::selectOne(<<<SQL
                SELECT reward.id, reward.customer_pass_id, reward.promotion_id,
                       reward.redeemed_at, reward.redeemed_by_user_id,
                       promotion.reward_title, promotion.reward_description, promotion.target_points,
                       promotion.ends_at, promotion.timezone_snapshot,
                       (reward.redeemed_at IS NULL AND promotion.status = 'published'
                        AND promotion.cancelled_at IS NULL
                        AND promotion.starts_at <= CAST(? AS timestamptz)
                        AND promotion.ends_at > CAST(? AS timestamptz))::integer AS can_redeem_reward,
                       COALESCE((SELECT SUM(visit.awarded_points) FROM visits AS visit
                                 WHERE visit.business_id = reward.business_id
                                   AND visit.customer_pass_id = reward.customer_pass_id
                                   AND visit.promotion_id = reward.promotion_id), 0) AS earned_points
                FROM reward_entitlements AS reward
                JOIN customer_passes AS pass ON pass.id = reward.customer_pass_id AND pass.business_id = reward.business_id
                JOIN businesses AS business ON business.id = pass.business_id
                JOIN promotions AS promotion ON promotion.id = reward.promotion_id AND promotion.business_id = reward.business_id
                WHERE business.user_id = ? AND pass.id = ? AND pass.{$column} = ?
                  AND reward.promotion_id = ? AND (?::bigint IS NULL OR reward.id = ?)
                SQL, [$found['checked_at'], $found['checked_at'], $actor?->id, $found['customer_pass_id'], $value, $promotionId, $entitlementId, $entitlementId]);
        } catch (QueryException|PDOException $exception) {
            $failure = CustomerPassFindingException::fromException($exception);
            $failure->log([
                'actor_id' => $actor?->id,
                'customer_pass_id' => $found['customer_pass_id'],
                'promotion_id' => $promotionId,
                'reward_entitlement_id' => $entitlementId,
                'read_context' => 'reward_receipt',
                'outcome' => 'unavailable',
            ]);

            throw $failure;
        }

        if ($row === null) {
            return null;
        }

        return [
            'reward_entitlement_id' => (int) $row->id,
            'customer_pass_id' => (int) $row->customer_pass_id,
            'promotion_id' => (int) $row->promotion_id,
            'redeemed_at' => $row->redeemed_at === null ? null : CarbonImmutable::parse($row->redeemed_at)->utc()->toISOString(),
            'redeemed_by_user_id' => $row->redeemed_by_user_id === null ? null : (int) $row->redeemed_by_user_id,
            'can_redeem_reward' => (int) $row->can_redeem_reward === 1,
            'promotion' => [
                'reward_title' => (string) $row->reward_title,
                'reward_description' => $row->reward_description,
                'earned_points' => (int) $row->earned_points,
                'target_points' => (int) $row->target_points,
                'deadline' => CarbonImmutable::parse($row->ends_at)->setTimezone($row->timezone_snapshot)->subDay()->format('d/m/Y'),
            ],
        ];
    }
}
