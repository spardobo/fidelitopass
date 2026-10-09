<?php

namespace App\Actions\Promotions;

use App\Enums\PromotionStatus;
use App\Models\Promotion;
use App\Models\User;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CancelPromotion
{
    /**
     * Creates the cancellation action with its authoritative PostgreSQL clock.
     *
     * @param  DatabaseClock  $databaseClock  Clock used after the Business and Promotion locks are held.
     */
    public function __construct(private readonly DatabaseClock $databaseClock) {}

    /**
     * Cancels an owner-authorized scheduled or active Promotion without changing its frozen publication facts.
     *
     * Locks the Business before its Promotion, captures one operation instant after both locks, and rejects
     * drafts, repeats, and originally ended windows without changing the stored aggregate.
     *
     * @param  User  $actor  Verified owner requesting cancellation.
     * @param  Promotion  $promotion  Promotion selected for cancellation.
     * @return Promotion Cancelled Promotion with its multiplier windows loaded.
     *
     * @throws AuthorizationException When the actor cannot update the Promotion.
     * @throws ModelNotFoundException When the actor has no Business or the Promotion is not owned by it.
     * @throws ValidationException When the Promotion is a draft, already cancelled, or ended.
     */
    public function handle(User $actor, Promotion $promotion): Promotion
    {
        return DB::transaction(function () use ($actor, $promotion): Promotion {
            $business = $actor->business()->lockForUpdate()->firstOrFail();
            $ownedPromotion = $business->promotions()
                ->whereKey($promotion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('update', $ownedPromotion);

            $operation = $this->databaseClock->captureForBusinessTimezone($business->timezone);

            if ($ownedPromotion->status === PromotionStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'promotion' => __('business.promotion.already_cancelled'),
                ]);
            }

            if ($ownedPromotion->status !== PromotionStatus::Published) {
                throw ValidationException::withMessages([
                    'promotion' => __('business.promotion.cancel_only_published'),
                ]);
            }

            $originalEnd = CarbonImmutable::parse((string) $ownedPromotion->getRawOriginal('ends_at'));

            if ($originalEnd->lessThanOrEqualTo($operation['instant'])) {
                throw ValidationException::withMessages([
                    'promotion' => __('business.promotion.cancel_ended'),
                ]);
            }

            $ownedPromotion->status = PromotionStatus::Cancelled;
            $ownedPromotion->cancelled_at = $operation['instant'];
            $ownedPromotion->setUpdatedAt($operation['instant']);
            $ownedPromotion->save();

            return $ownedPromotion->load('extraPoints');
        });
    }
}
