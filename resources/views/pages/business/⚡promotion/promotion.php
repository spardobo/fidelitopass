<?php

use App\Actions\Promotions\SavePromotionDraft;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Support\DatabaseClock;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

new #[Layout('layouts::app'), Title('business.promotion.title')] class extends Component
{
    #[Locked]
    public ?string $promotionPublicId = null;

    #[Locked]
    public string $minimumStartDate = '';

    public string $rewardTitle = '';

    public string $rewardDescription = '';

    public string $targetPoints = '';

    public string $localStartDate = '';

    public string $localEndDate = '';

    /** @var array<int, mixed> */
    public array $extraPoints = [];

    public string $draftWeekday = '';

    public string $draftMultiplier = '2';

    public string $draftMode = 'all_day';

    public string $draftStartTime = '';

    public string $draftEndTime = '';

    /**
     * Initialize the editor from the route-bound public Promotion ID and current Business timezone.
     * Authorize its context, set the local minimum date, and copy an existing draft into form state.
     *
     * @param  Promotion|null  $promotion  Promotion resolved by the route's public identifier, if editing.
     *
     * @throws AuthorizationException When the actor cannot create or edit the Promotion.
     * @throws ModelNotFoundException When the Business or selected Promotion is unavailable.
     * @throws HttpException When the selected Promotion is no longer a draft.
     */
    public function mount(?Promotion $promotion = null): void
    {
        if ($promotion !== null) {
            $this->promotionPublicId = $promotion->public_id;
        }

        $currentPromotion = $this->authorizeEditor();
        $this->minimumStartDate = app(DatabaseClock::class)
            ->captureForBusinessTimezone($this->business()->timezone)['business_date'];

        if ($currentPromotion !== null) {
            $this->fillFromPromotion($currentPromotion);
        }
    }

    /**
     * Validate the rule-builder entry and append it without persisting the draft.
     * On success, sort the accepted list and reset the builder.
     *
     * @throws AuthorizationException When the actor is not authorized to add a rule in this editor.
     * @throws ModelNotFoundException When the actor has no Business or the Promotion is not owned by them.
     * @throws HttpException When the selected Promotion is no longer a draft.
     * @throws ValidationException When the entry is invalid or conflicts with an accepted window.
     */
    public function addExtraPoint(): void
    {
        $this->authorizeEditor();
        $this->resetValidation([
            'draftWeekday', 'draftMultiplier', 'draftMode', 'draftStartTime', 'draftEndTime', 'extraPoints',
        ]);

        try {
            $validated = $this->validate([
                'draftWeekday' => ['required', 'integer', 'between:1,7'],
                'draftMultiplier' => ['required', 'integer', 'in:2,3,5'],
                'draftMode' => ['required', 'in:all_day,timed'],
                'draftStartTime' => [
                    $this->draftMode === 'timed' ? 'required' : 'nullable',
                    'date_format:H:i',
                ],
                'draftEndTime' => [
                    $this->draftMode === 'timed' ? 'required' : 'nullable',
                    'date_format:H:i',
                ],
            ], [
                'draftWeekday.required' => __('business.promotion.rule_day_required'),
            ]);

            if ($validated['draftMode'] === 'timed' && $validated['draftStartTime'] >= $validated['draftEndTime']) {
                throw ValidationException::withMessages([
                    'draftStartTime' => __('business.promotion.rule_time_order'),
                ]);
            }

            $rule = [
                'weekday' => (int) $validated['draftWeekday'],
                'start_time' => $validated['draftMode'] === 'timed' ? $validated['draftStartTime'] : null,
                'end_time' => $validated['draftMode'] === 'timed' ? $validated['draftEndTime'] : null,
                'multiplier' => (int) $validated['draftMultiplier'],
            ];

            app(SavePromotionDraft::class)->validateExtraPointWindows([
                ...$this->normalizedExtraPoints(),
                $rule,
            ]);
        } catch (ValidationException $exception) {
            $this->throwActionValidationException($exception);
        }

        $this->extraPoints[] = $rule;
        $this->sortExtraPoints();

        $this->resetRuleDraft();
        $this->resetValidation(['extraPoints', 'extra_points']);
    }

    /**
     * Remove one accepted extra-point rule by its current list index, reindex the list, and clear related validation errors.
     *
     * @param  int  $index  Zero-based index of the rule displayed in the accepted list.
     *
     * @throws AuthorizationException When the actor is not authorized to remove a rule in this editor.
     * @throws ModelNotFoundException When the actor has no Business or the Promotion is not owned by them.
     * @throws HttpException When the selected Promotion is no longer a draft.
     */
    public function removeExtraPoint(int $index): void
    {
        $this->authorizeEditor();

        if (! array_key_exists($index, $this->extraPoints)) {
            return;
        }

        unset($this->extraPoints[$index]);
        $this->extraPoints = array_values($this->extraPoints);
        $this->resetValidation(['extraPoints', 'extra_points']);
    }

    /**
     * Reset only the unaccepted rule-builder entry and its validation errors, leaving accepted rules and promotion fields unchanged.
     *
     * @throws AuthorizationException When the actor is not authorized to discard a rule draft in this editor.
     * @throws ModelNotFoundException When the actor has no Business or the Promotion is not owned by them.
     * @throws HttpException When the selected Promotion is no longer a draft.
     */
    public function discardRuleDraft(): void
    {
        $this->authorizeEditor();
        $this->resetRuleDraft();
        $this->resetValidation([
            'draftWeekday', 'draftMultiplier', 'draftMode', 'draftStartTime', 'draftEndTime',
            'extraPoints', 'extra_points',
        ]);
    }

    /**
     * Validate pending builder state and persist the complete draft.
     * Retain inline errors and show a toast on failure; flash success before returning to Pase.
     *
     * @param  SavePromotionDraft  $savePromotionDraft  Transactional action that validates and saves the draft aggregate.
     *
     * @throws AuthorizationException When the actor cannot create or edit the draft.
     * @throws ModelNotFoundException When the Business or selected Promotion cannot be resolved for this actor.
     * @throws HttpException When the selected Promotion is no longer a draft.
     * @throws ValidationException When the action rejects submitted draft fields or rule windows.
     */
    public function save(SavePromotionDraft $savePromotionDraft): void
    {
        $promotion = $this->authorizeEditor();
        $this->resetValidation();

        if ($this->hasPendingRuleInput()) {
            $this->addError('extraPoints', __('business.promotion.incomplete_rule'));
            Flux::toast(__('business.promotion.validation_notice'), null, 5000, 'danger');

            return;
        }

        try {
            $savePromotionDraft->handle(Auth::user(), [
                'local_start_date' => $this->localStartDate,
                'local_end_date' => $this->localEndDate,
                'target_points' => $this->targetPoints,
                'reward_title' => $this->rewardTitle,
                'reward_description' => $this->rewardDescription,
                'extra_points' => $this->normalizedExtraPoints(),
            ], $promotion);
        } catch (ValidationException $exception) {
            $this->throwActionValidationException($exception);
        }

        Session::flash('business.promotion.draft_saved', true);
        $this->redirectRoute('business.pass', navigate: true);
    }

    /**
     * Recheck editor authorization and return to Pase without persisting unsaved form state.
     *
     * @throws AuthorizationException When the actor cannot access this editor.
     * @throws ModelNotFoundException When the Business or selected Promotion cannot be resolved for this actor.
     * @throws HttpException When the selected Promotion is no longer a draft.
     */
    public function cancel(): void
    {
        $this->authorizeEditor();

        $this->redirectRoute('business.pass', navigate: true);
    }

    /**
     * Resolve and authorize the current actor's Business for editor reads.
     *
     * @return Business Business owned by the authenticated actor.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     */
    #[Computed]
    public function business(): Business
    {
        $business = Auth::user()->business()->firstOrFail();
        Gate::authorize('update', $business);

        return $business;
    }

    /**
     * Report whether the current editor is bound to an existing public Promotion ID.
     *
     * @return bool True while editing a draft; false while creating one.
     */
    #[Computed]
    public function isEditing(): bool
    {
        return $this->promotionPublicId !== null;
    }

    /**
     * Build the display strings for the currently accepted extra-point rules.
     *
     * @return array<int, string> Localized weekday, time period, and multiplier summaries.
     */
    #[Computed]
    public function ruleSummary(): array
    {
        return array_map(function (array $rule): string {
            $day = __('business.promotion.weekdays.'.$rule['weekday']);
            $period = $rule['start_time'] === null
                ? __('business.promotion.all_day')
                : $rule['start_time'].'–'.$rule['end_time'];

            return $day.' · '.$period.' · ×'.$rule['multiplier'];
        }, $this->normalizedExtraPoints());
    }

    /**
     * Authorize create or update access and resolve the existing draft when applicable.
     *
     * @return Promotion|null Owned draft being edited, or null for the create flow.
     *
     * @throws AuthorizationException When the actor cannot create or update the Promotion.
     * @throws ModelNotFoundException When the Business or selected Promotion is missing.
     * @throws HttpException When the selected Promotion is not a draft.
     */
    private function authorizeEditor(): ?Promotion
    {
        $business = $this->business();

        if ($this->promotionPublicId === null) {
            Gate::authorize('create', [Promotion::class, $business]);

            return null;
        }

        return $this->resolvePromotion();
    }

    /**
     * Load the current actor's Promotion by public ID and reject non-draft records.
     *
     * @return Promotion|null Owned draft with its extra-point windows loaded, or null when no ID is set.
     *
     * @throws AuthorizationException When the actor cannot update the Promotion.
     * @throws ModelNotFoundException When the public ID is not owned by the current Business.
     * @throws HttpException When the selected Promotion is not a draft.
     */
    private function resolvePromotion(): ?Promotion
    {
        if ($this->promotionPublicId === null) {
            return null;
        }

        $promotion = $this->business()->promotions()
            ->where('public_id', $this->promotionPublicId)
            ->with('extraPoints')
            ->firstOrFail();

        Gate::authorize('update', $promotion);
        abort_unless($promotion->status === PromotionStatus::Draft, 404);

        return $promotion;
    }

    /**
     * Copy persisted draft values and its rules into component form state without changing the model.
     *
     * @param  Promotion  $promotion  Owned draft with its extra-point relationship loaded.
     */
    private function fillFromPromotion(Promotion $promotion): void
    {
        $this->rewardTitle = $promotion->reward_title;
        $this->rewardDescription = $promotion->reward_description ?? '';
        $this->targetPoints = (string) $promotion->target_points;
        $this->localStartDate = $this->dateForInput($promotion, 'local_start_date');
        $this->localEndDate = $this->dateForInput($promotion, 'local_end_date');
        $this->extraPoints = $this->storedExtraPoints($promotion);
    }

    /**
     * Format a persisted calendar-date attribute for an HTML date input without timezone conversion.
     *
     * @param  Promotion  $promotion  Draft whose date attribute is being read.
     * @param  string  $attribute  Date attribute name, expected to be a local start or end date.
     * @return string ISO calendar date in the input format, or an empty string when absent.
     */
    private function dateForInput(Promotion $promotion, string $attribute): string
    {
        $date = $promotion->getAttribute($attribute);

        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return filled($date) ? (string) $date : '';
    }

    /**
     * Convert persisted multiplier windows to ordered editor values.
     *
     * @param  Promotion  $promotion  Draft with its extra-point relationship loaded.
     * @return array<int, array{weekday: int|string, start_time: ?string, end_time: ?string, multiplier: int|string}> Ordered rule fields formatted for the editor controls.
     */
    private function storedExtraPoints(Promotion $promotion): array
    {
        return $promotion->extraPoints
            ->sortBy(static fn ($rule): string => sprintf(
                '%d-%s-%010d',
                $rule->weekday,
                $rule->start_time ?? '',
                $rule->id,
            ))
            ->map(static fn ($rule): array => [
                'weekday' => $rule->weekday,
                'start_time' => $rule->start_time === null ? null : substr($rule->start_time, 0, 5),
                'end_time' => $rule->end_time === null ? null : substr($rule->end_time, 0, 5),
                'multiplier' => $rule->multiplier,
            ])
            ->values()
            ->all();
    }

    /**
     * Normalize client-controlled Livewire rule entries into the action's input keys.
     *
     * The returned values are still untrusted; the save action performs authoritative validation.
     *
     * @return array<int, array{weekday: mixed, start_time: mixed, end_time: mixed, multiplier: mixed}> Reindexed rule records with required keys present.
     */
    private function normalizedExtraPoints(): array
    {
        return array_values(array_map(static function (mixed $rule): array {
            if (! is_array($rule)) {
                return [
                    'weekday' => '',
                    'start_time' => null,
                    'end_time' => null,
                    'multiplier' => '',
                ];
            }

            return [
                'weekday' => $rule['weekday'] ?? '',
                'start_time' => filled($rule['start_time'] ?? null) ? $rule['start_time'] : null,
                'end_time' => filled($rule['end_time'] ?? null) ? $rule['end_time'] : null,
                'multiplier' => $rule['multiplier'] ?? '',
            ];
        }, $this->extraPoints));
    }

    /**
     * Sort accepted rules in place by ISO weekday and then start time for stable display and submission.
     */
    private function sortExtraPoints(): void
    {
        usort($this->extraPoints, static fn (array $first, array $second): int => [
            (int) $first['weekday'],
            $first['start_time'] ?? '',
        ] <=> [
            (int) $second['weekday'],
            $second['start_time'] ?? '',
        ]);
    }

    /**
     * Determine whether the builder contains values that have not been accepted as a rule.
     *
     * @return bool True when saving would otherwise discard an unsubmitted builder entry.
     */
    private function hasPendingRuleInput(): bool
    {
        return $this->draftWeekday !== ''
            || $this->draftMultiplier !== '2'
            || $this->draftMode !== 'all_day'
            || $this->draftStartTime !== ''
            || $this->draftEndTime !== '';
    }

    /**
     * Map action validation paths to component fields, show the failure toast, and rethrow for Livewire.
     *
     * @param  ValidationException  $exception  Validation failure raised by the draft action or rule validator.
     * @return never This method always throws the translated validation exception.
     *
     * @throws ValidationException With component-compatible error keys and the original validation messages.
     */
    private function throwActionValidationException(ValidationException $exception): never
    {
        $messages = [];

        foreach ($exception->errors() as $path => $fieldMessages) {
            [$field, $childPath] = array_pad(explode('.', $path, 2), 2, null);
            $componentPath = Str::camel($field).($childPath === null ? '' : '.'.$childPath);
            $messages[$componentPath] = [...($messages[$componentPath] ?? []), ...$fieldMessages];
        }

        Flux::toast(__('business.promotion.validation_notice'), null, 5000, 'danger');

        throw ValidationException::withMessages($messages);
    }

    /**
     * Restore pending weekday, multiplier, mode, and time values to their unconfigured defaults.
     */
    private function resetRuleDraft(): void
    {
        $this->draftWeekday = '';
        $this->draftMultiplier = '2';
        $this->draftMode = 'all_day';
        $this->draftStartTime = '';
        $this->draftEndTime = '';
    }
};
