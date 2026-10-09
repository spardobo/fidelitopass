<?php

namespace App\Actions\Promotions;

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use App\Support\DatabaseClock;
use App\Support\PromotionDraftValidator;
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
     * @param  PromotionDraftValidator  $draftValidator  Shared pure validation for submitted terms, rules, and local dates.
     */
    public function __construct(
        private readonly DatabaseClock $databaseClock,
        private readonly PromotionDraftValidator $draftValidator,
    ) {}

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
        return $this->publish($actor, $promotion, $confirmedTimezone);
    }

    /**
     * Publish the complete submitted aggregate, creating it directly or replacing a selected draft atomically.
     *
     * Validates the untrusted fields before persistence, then locks the Business before an optional owned draft.
     * One post-lock PostgreSQL instant supplies date validation and the publication audit timestamps.
     *
     * @param  User  $actor  Verified Business owner requesting publication.
     * @param  array<string, mixed>  $input  Untrusted terms and complete extra-point rule set from the review screen.
     * @param  string  $confirmedTimezone  Business timezone explicitly confirmed for these submitted local dates.
     * @param  Promotion|null  $draft  Existing owned draft to replace, or null to create the published aggregate.
     * @return Promotion Published Promotion with its complete extra-point rules loaded.
     *
     * @throws AuthorizationException When the actor cannot create or update the Promotion.
     * @throws ModelNotFoundException When the actor has no Business or the selected draft is not owned by it.
     * @throws ValidationException When submitted fields, dates, status, timezone, or occupancy are invalid.
     */
    public function handleSubmitted(
        User $actor,
        array $input,
        string $confirmedTimezone,
        ?Promotion $draft = null,
    ): Promotion {
        $data = $this->draftValidator->validateInput($input);

        return $this->publish($actor, $draft, $confirmedTimezone, $data);
    }

    /**
     * Publish either the persisted draft terms or a validated submitted aggregate in one transaction.
     *
     * @param  User  $actor  Verified Business owner requesting publication.
     * @param  Promotion|null  $promotion  Existing draft selected for publication, or null to create.
     * @param  string  $confirmedTimezone  Business timezone confirmed during review.
     * @param  array<string, mixed>|null  $data  Validated submitted terms, or null to preserve persisted draft terms.
     * @return Promotion Published Promotion with its complete extra-point rules loaded.
     *
     * @throws AuthorizationException When the actor cannot create or update the Promotion.
     * @throws ModelNotFoundException When the actor has no Business or the selected draft is not owned by it.
     * @throws ValidationException When terms are invalid, confirmation is stale, the Promotion is not a draft, or occupancy overlaps.
     */
    private function publish(
        User $actor,
        ?Promotion $promotion,
        string $confirmedTimezone,
        ?array $data = null,
    ): Promotion {
        return DB::transaction(function () use ($actor, $promotion, $confirmedTimezone, $data): Promotion {
            $business = $actor->business()->lockForUpdate()->firstOrFail();

            if ($promotion === null) {
                Gate::forUser($actor)->authorize('create', [Promotion::class, $business]);
                $published = $business->promotions()->make();
            } else {
                $published = $business->promotions()
                    ->whereKey($promotion->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                Gate::forUser($actor)->authorize('update', $published);

                if ($published->status !== PromotionStatus::Draft) {
                    throw ValidationException::withMessages([
                        'promotion' => __('business.promotion.only_drafts_can_be_published'),
                    ]);
                }
            }

            if ($confirmedTimezone !== $business->timezone) {
                throw ValidationException::withMessages([
                    'promotion' => __('business.promotion.timezone_changed_since_review'),
                ]);
            }

            $operation = $this->databaseClock->captureForBusinessTimezone($business->timezone);
            if ($data !== null) {
                $published->fill([
                    'local_start_date' => $data['local_start_date'],
                    'local_end_date' => $data['local_end_date'],
                    'target_points' => $data['target_points'],
                    'reward_title' => $data['reward_title'],
                    'reward_description' => $data['reward_description'] ?? null,
                ]);
            }

            $localStartDate = $data['local_start_date'] ?? (string) $published->getRawOriginal('local_start_date');
            $localEndDate = $data['local_end_date'] ?? (string) $published->getRawOriginal('local_end_date');
            $this->draftValidator->validateCurrentStartDate($localStartDate, $operation['business_date']);

            $window = DB::selectOne(<<<'SQL'
                SELECT (?::date::timestamp AT TIME ZONE ?) AS starts_at,
                       ((?::date + 1)::timestamp AT TIME ZONE ?) AS ends_at
                SQL, [$localStartDate, $business->timezone, $localEndDate, $business->timezone]);

            if ($this->hasEffectiveOverlap($business, (string) $window->starts_at, (string) $window->ends_at)) {
                throw ValidationException::withMessages([
                    'promotion' => __('business.promotion.publication_window_overlaps'),
                ]);
            }

            $published->local_start_date = null;
            $published->local_end_date = null;
            $published->timezone_snapshot = $business->timezone;
            $published->starts_at = $window->starts_at;
            $published->ends_at = $window->ends_at;
            $published->status = PromotionStatus::Published;
            if ($promotion === null) {
                $published->setCreatedAt($operation['instant']);
            }
            $published->setUpdatedAt($operation['instant']);
            $published->save();

            if ($data !== null) {
                $published->extraPoints()->delete();
                $published->extraPoints()->createMany($data['extra_points']);
            }

            return $published->load('extraPoints');
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
