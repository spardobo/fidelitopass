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
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SavePromotionDraft
{
    /**
     * Create the action with its authoritative database clock.
     *
     * @param  DatabaseClock  $databaseClock  Clock used to capture the database instant and Business-local date.
     */
    public function __construct(private readonly DatabaseClock $databaseClock) {}

    /**
     * Validate and atomically create or replace an owner-authorized draft and its complete rule set.
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
        $data = $this->validateInput($input);

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
            $this->validateCurrentStartDate($data['local_start_date'], $operation['business_date']);

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

    /**
     * Validate the untrusted draft payload and its cross-window invariants.
     *
     * @param  array<string, mixed>  $input  Client-provided draft fields and rule entries.
     * @return array<string, mixed> Validated values with normalized child rule fields.
     *
     * @throws ValidationException When a field or rule window violates the draft contract.
     */
    private function validateInput(array $input): array
    {
        $data = Validator::make($input, [
            'local_start_date' => ['required', 'date_format:Y-m-d'],
            'local_end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:local_start_date'],
            'target_points' => ['required', 'integer', 'min:1'],
            'reward_title' => ['required', 'string'],
            'reward_description' => ['nullable', 'string'],
            'extra_points' => ['present', 'array'],
            'extra_points.*' => ['array:weekday,start_time,end_time,multiplier'],
            'extra_points.*.weekday' => ['required', 'integer', 'between:1,7'],
            'extra_points.*.multiplier' => ['required', 'integer', 'in:2,3,5'],
            'extra_points.*.start_time' => ['nullable', 'required_with:extra_points.*.end_time', 'date_format:H:i'],
            'extra_points.*.end_time' => ['nullable', 'required_with:extra_points.*.start_time', 'date_format:H:i'],
        ])->validate();

        $this->validateExtraPointWindows($data['extra_points']);

        return $data;
    }

    /**
     * Ensure the submitted start date is not earlier than the locked Business's local database date.
     * Raise a validation error when the submitted date is earlier.
     *
     * @param  string  $startDate  ISO calendar date submitted for the draft.
     * @param  string  $businessToday  ISO calendar date derived from the captured database instant and Business timezone.
     *
     * @throws ValidationException When the submitted date precedes the Business-local current date.
     */
    private function validateCurrentStartDate(string $startDate, string $businessToday): void
    {
        Validator::make(
            ['local_start_date' => $startDate],
            ['local_start_date' => ['after_or_equal:'.$businessToday]],
            ['local_start_date.after_or_equal' => __('business.promotion.start_date_current')],
        )->validate();
    }

    /**
     * Reject invalid same-day combinations while allowing valid disjoint or touching multiplier windows.
     *
     * @param  array<int, array{weekday: int|string, start_time?: string|null, end_time?: string|null, multiplier: int|string}>  $windows  Untrusted rule windows already checked for field shape and scalar formats.
     *
     * @throws ValidationException When a window is incomplete, reversed, overlapping, duplicated, or conflicts with an all-day rule.
     */
    public function validateExtraPointWindows(array $windows): void
    {
        $byWeekday = [];

        foreach ($windows as $index => $window) {
            $weekday = (int) $window['weekday'];
            $start = $window['start_time'] ?? null;
            $end = $window['end_time'] ?? null;

            if (($start === null) !== ($end === null)) {
                $field = $start === null ? 'start_time' : 'end_time';
                $attribute = __('validation.attributes.extra_points.*.'.$field);

                throw ValidationException::withMessages([
                    "extra_points.{$index}.{$field}" => __('validation.required', ['attribute' => $attribute]),
                ]);
            }

            if ($start !== null && $start >= $end) {
                throw ValidationException::withMessages([
                    'extra_points' => __('business.promotion.extra_points_window_order'),
                ]);
            }

            $byWeekday[$weekday][] = [$start, $end];
        }

        foreach ($byWeekday as $dayWindows) {
            if (count($dayWindows) > 1 && in_array([null, null], $dayWindows, true)) {
                throw ValidationException::withMessages([
                    'extra_points' => __('business.promotion.extra_points_all_day_conflict'),
                ]);
            }

            usort($dayWindows, fn (array $left, array $right): int => strcmp($left[0], $right[0]));

            for ($index = 1; $index < count($dayWindows); $index++) {
                if ($dayWindows[$index][0] < $dayWindows[$index - 1][1]) {
                    throw ValidationException::withMessages([
                        'extra_points' => __('business.promotion.extra_points_overlap'),
                    ]);
                }
            }
        }
    }
}
