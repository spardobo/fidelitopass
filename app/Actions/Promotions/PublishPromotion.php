<?php

namespace App\Actions\Promotions;

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use App\Support\DatabaseClock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PublishPromotion
{
    /**
     * Create the publication action with its authoritative PostgreSQL clock.
     *
     * @param  DatabaseClock  $databaseClock  Clock used after publication locks are acquired.
     */
    public function __construct(private readonly DatabaseClock $databaseClock) {}

    /**
     * Publish an owner-authorized draft after confirming its current Business timezone.
     *
     * Locks the Business and selected draft in that order, captures one post-lock operation instant,
     * rejects stale local dates and overlapping effective occupancy, then freezes the same Promotion row.
     *
     * @param  User  $actor  Verified owner requesting publication.
     * @param  Promotion  $promotion  Draft selected for publication.
     * @param  string  $confirmedTimezone  Business timezone the owner reviewed before confirmation.
     * @return Promotion Published Promotion with its frozen UTC window and multiplier rules loaded.
     *
     * @throws AuthorizationException When the actor cannot update the Promotion.
     * @throws ModelNotFoundException When the actor has no Business or the Promotion is not owned by it.
     * @throws ValidationException When the draft is stale, already published, or overlaps effective occupancy.
     */
    public function handle(User $actor, Promotion $promotion, string $confirmedTimezone): Promotion
    {
        return DB::transaction(function () use ($actor, $promotion, $confirmedTimezone): Promotion {
            $business = $actor->business()->lockForUpdate()->firstOrFail();
            $draft = $business->promotions()
                ->whereKey($promotion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('update', $draft);

            if ($draft->status !== PromotionStatus::Draft) {
                throw ValidationException::withMessages([
                    'promotion' => __('business.promotion.only_drafts_can_be_published'),
                ]);
            }

            if ($confirmedTimezone !== $business->timezone) {
                throw ValidationException::withMessages([
                    'promotion' => __('business.promotion.timezone_changed_since_review'),
                ]);
            }

            $operation = $this->databaseClock->captureForBusinessTimezone($business->timezone);
            $localStartDate = (string) $draft->getRawOriginal('local_start_date');
            $localEndDate = (string) $draft->getRawOriginal('local_end_date');

            if ($localStartDate < $operation['business_date']) {
                throw ValidationException::withMessages([
                    'local_start_date' => __('business.promotion.start_date_current'),
                ]);
            }

            $window = DB::selectOne(<<<'SQL'
                SELECT (?::date::timestamp AT TIME ZONE ?) AS starts_at,
                       ((?::date + 1)::timestamp AT TIME ZONE ?) AS ends_at
                SQL, [$localStartDate, $business->timezone, $localEndDate, $business->timezone]);

            if ($this->hasEffectiveOverlap($business, (string) $window->starts_at, (string) $window->ends_at)) {
                throw ValidationException::withMessages([
                    'promotion' => __('business.promotion.publication_window_overlaps'),
                ]);
            }

            $draft->local_start_date = null;
            $draft->local_end_date = null;
            $draft->timezone_snapshot = $business->timezone;
            $draft->starts_at = $window->starts_at;
            $draft->ends_at = $window->ends_at;
            $draft->status = PromotionStatus::Published;
            $draft->setUpdatedAt($operation['instant']);
            $draft->save();

            return $draft->load('extraPoints');
        });
    }

    /**
     * Report whether another published or cancelled Promotion occupies any part of the candidate window.
     *
     * Cancelled intervals end at the earlier of cancellation or their original exclusive end; cancellations
     * before the original start produce no occupancy. Strict inequalities preserve half-open touching windows.
     *
     * @param  Business  $business  Locked Business whose publication intervals are compared.
     * @param  string  $startsAt  Candidate inclusive UTC start instant.
     * @param  string  $endsAt  Candidate exclusive UTC end instant.
     * @return bool Whether an effective published interval intersects the candidate.
     */
    private function hasEffectiveOverlap(Business $business, string $startsAt, string $endsAt): bool
    {
        return $business->promotions()
            ->whereIn('status', [PromotionStatus::Published->value, PromotionStatus::Cancelled->value])
            ->whereRaw('promotions.starts_at < ?::timestamptz', [$endsAt])
            ->whereRaw(
                'CASE WHEN promotions.status = ? THEN LEAST(promotions.ends_at, promotions.cancelled_at) ELSE promotions.ends_at END > ?::timestamptz',
                [PromotionStatus::Cancelled->value, $startsAt],
            )
            ->whereRaw(
                'CASE WHEN promotions.status = ? THEN LEAST(promotions.ends_at, promotions.cancelled_at) > promotions.starts_at ELSE TRUE END',
                [PromotionStatus::Cancelled->value],
            )
            ->exists();
    }
}
