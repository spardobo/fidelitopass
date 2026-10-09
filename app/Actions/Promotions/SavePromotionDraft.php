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

class SavePromotionDraft
{
    /**
     * Creates the action with its authoritative database clock.
     *
     * @param  DatabaseClock  $databaseClock  Clock used to capture the database instant and Business-local date.
     * @param  PromotionDraftValidator  $draftValidator  Shared pure validation rules for Promotion draft input.
     */
    public function __construct(
        private readonly DatabaseClock $databaseClock,
        private readonly PromotionDraftValidator $draftValidator,
    ) {}

    /**
     * Validates and atomically create or replace an owner-authorized draft and its complete rule set.
     *
     * Locks the Business before an existing Promotion, checks ownership and draft status, captures one
     * Business-local database date, then persists the parent and child windows in one transaction.
     *
     * @param  User  $actor  Verified owner requesting the draft mutation.
     * @param  array<string, mixed>  $input  Untrusted draft fields and extra-point windows to validate.
     * @param  Promotion|null  $promotion  Existing draft selected for update, or null to create.
     * @return Promotion The saved draft with its extra-point windows loaded.
     *
     * @throws AuthorizationException When the actor cannot create or update the draft.
     * @throws ModelNotFoundException When the actor has no Business or the selected draft is not owned by it.
     * @throws ValidationException When draft fields, dates, status, or extra-point windows are invalid.
     */
    public function handle(User $actor, array $input, ?Promotion $promotion = null): Promotion
    {
        $data = $this->draftValidator->validateInput($input);

        return DB::transaction(function () use ($actor, $data, $promotion): Promotion {
            $business = $actor->business()->lockForUpdate()->firstOrFail();

            if ($promotion === null) {
                Gate::forUser($actor)->authorize('create', [Promotion::class, $business]);
                $draft = $business->promotions()->make();
                $draft->status = PromotionStatus::Draft;
            } else {
                $draft = $business->promotions()
                    ->whereKey($promotion->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                Gate::forUser($actor)->authorize('update', $draft);

                if ($draft->status !== PromotionStatus::Draft) {
                    throw ValidationException::withMessages([
                        'promotion' => __('business.promotion.draft_edit_only'),
                    ]);
                }
            }

            $operation = $this->databaseClock->captureForBusinessTimezone($business->timezone);
            $this->draftValidator->validateCurrentStartDate($data['local_start_date'], $operation['business_date']);

            $draft->fill([
                'local_start_date' => $data['local_start_date'],
                'local_end_date' => $data['local_end_date'],
                'target_points' => $data['target_points'],
                'reward_title' => $data['reward_title'],
                'reward_description' => $data['reward_description'] ?? null,
            ]);
            if ($promotion === null) {
                $draft->setCreatedAt($operation['instant']);
            }
            $draft->setUpdatedAt($operation['instant']);
            $draft->save();

            $draft->extraPoints()->delete();
            $draft->extraPoints()->createMany($data['extra_points']);

            return $draft->load('extraPoints');
        });
    }
}
