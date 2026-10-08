<main
    class="app-theme app-workspace app-promotion-page"
    data-test="promotion-editor"
    wire:ignore.self
    x-data="{
        initialState: @js([
            'rewardTitle' => $rewardTitle,
            'rewardDescription' => $rewardDescription,
            'targetPoints' => $targetPoints,
            'localStartDate' => $localStartDate,
            'localEndDate' => $localEndDate,
            'extraPoints' => $extraPoints,
            'draftWeekday' => $draftWeekday,
            'draftMultiplier' => $draftMultiplier,
            'draftMode' => $draftMode,
            'draftStartTime' => $draftStartTime,
            'draftEndTime' => $draftEndTime,
        ]),
        /** Compare current server-owned editor fields with their mounted baseline.
         * @returns {boolean} True when any promotion field or pending rule-builder value differs from its baseline.
         */
        hasUnsavedChanges() {
            return JSON.stringify({
                rewardTitle: this.$wire.rewardTitle,
                rewardDescription: this.$wire.rewardDescription,
                targetPoints: this.$wire.targetPoints,
                localStartDate: this.$wire.localStartDate,
                localEndDate: this.$wire.localEndDate,
                extraPoints: this.$wire.extraPoints,
                draftWeekday: this.$wire.draftWeekday,
                draftMultiplier: this.$wire.draftMultiplier,
                draftMode: this.$wire.draftMode,
                draftStartTime: this.$wire.draftStartTime,
                draftEndTime: this.$wire.draftEndTime,
            }) !== JSON.stringify(this.initialState);
        },
    }"
>
    <header class="space-y-6">
        <div class="flex min-h-11 items-center">
            <a
                x-show="! hasUnsavedChanges()"
                href="{{ route('business.pass') }}"
                wire:navigate
                wire:loading.attr="inert"
                wire:loading.class="opacity-60"
                wire:target="save"
                class="app-button inline-flex min-h-11 items-center gap-2 text-sm font-medium text-app-accent hover:underline"
            >
                <flux:icon.arrow-left variant="outline" class="size-4" />
                {{ __('business.promotion.back_to_pass') }}
            </a>
            <button
                x-cloak
                x-show="hasUnsavedChanges()"
                x-on:click="$dispatch('modal-show', { name: 'discard-promotion-draft' })"
                type="button"
                wire:loading.attr="disabled"
                wire:target="save"
                class="app-button inline-flex min-h-11 items-center gap-2 text-sm font-medium text-app-accent hover:underline"
            >
                <flux:icon.arrow-left variant="outline" class="size-4" />
                {{ __('business.promotion.back_to_pass') }}
            </button>
        </div>

        <div class="space-y-2">
            <p class="app-role-body! text-app-accent">{{ $this->business->name }} · {{ __('business.pass.business_label') }}</p>
            <flux:heading level="1" class="app-heading">
                {{ $this->isEditing ? __('business.promotion.edit_title') : __('business.promotion.create_title') }}
            </flux:heading>
            <flux:text class="app-role-intro! text-app-ink-secondary!">
                {{ __('business.promotion.intro') }}
            </flux:text>
        </div>
    </header>

    <form wire:submit="save" novalidate class="space-y-8">
        <div class="grid min-w-0 gap-8 lg:grid-cols-[minmax(0,2.4fr)_minmax(17rem,1fr)]">
            <div class="min-w-0 space-y-5">
                <section aria-labelledby="promotion-general-heading" class="space-y-6 rounded-2xl border border-app-priority-border bg-app-emphasis p-4 sm:p-6">
                    <div class="space-y-1">
                        <div class="flex items-baseline gap-3">
                            <span class="app-role-support! font-semibold! text-app-accent-text">01</span>
                            <flux:heading level="2" id="promotion-general-heading" class="app-role-section! text-app-ink-priority!">
                                {{ __('business.promotion.general_heading') }}
                            </flux:heading>
                        </div>
                        <flux:text class="app-role-support! text-app-ink-secondary!">
                            {{ __('business.promotion.general_description') }}
                        </flux:text>
                    </div>

                    <div class="space-y-5">
                        <flux:field>
                            <flux:label for="reward-title" class="app-label!">
                                <span aria-hidden="true" class="text-app-danger-ink me-1">*</span> {{ __('business.promotion.reward_title') }}
                            </flux:label>
                            <flux:input
                                id="reward-title"
                                wire:model="rewardTitle"
                                required
                                autocomplete="off"
                                :placeholder="__('business.promotion.reward_title_placeholder')"
                                input:class="app-input!"
                            />
                            <flux:error name="rewardTitle" class="app-error" />
                        </flux:field>

                        <flux:field>
                            <flux:label for="reward-description" class="app-label! flex items-center gap-2">
                                {{ __('business.promotion.reward_description') }}
                                <flux:badge color="amber" class="app-role-support! font-medium! dark:bg-amber-400/30!">
                                    {{ __('business.promotion.optional') }}
                                </flux:badge>
                            </flux:label>
                            <flux:textarea
                                id="reward-description"
                                wire:model="rewardDescription"
                                rows="3"
                                :placeholder="__('business.promotion.reward_description_placeholder')"
                                class="app-input!"
                            />
                                <flux:error name="rewardDescription" class="app-error" />
                        </flux:field>

                        <div class="min-w-0 space-y-2">
                            <flux:field>
                                <flux:label for="target-points" class="app-label!">
                                    <span aria-hidden="true" class="text-app-danger-ink me-1">*</span> {{ __('business.promotion.target_points') }}
                                </flux:label>
                                <flux:input
                                    id="target-points"
                                    wire:model="targetPoints"
                                    type="number"
                                    min="1"
                                    required
                                    inputmode="numeric"
                                    aria-describedby="target-points-help"
                                    class="max-w-48"
                                    input:class="app-input!"
                                />
                                <flux:error name="targetPoints" class="app-error" />
                            </flux:field>
                            <flux:description id="target-points-help" class="app-role-support! text-app-ink-help!">
                                {{ __('business.promotion.target_points_help') }}
                            </flux:description>
                        </div>

                        <div class="grid min-w-0 items-start gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label for="local-start-date" class="app-label!">
                                    <span aria-hidden="true" class="text-app-danger-ink me-1">*</span> {{ __('business.promotion.start_date') }}
                                </flux:label>
                                <flux:input
                                    id="local-start-date"
                                    wire:model="localStartDate"
                                    type="date"
                                    required
                                    min="{{ $minimumStartDate }}"
                                    input:class="app-input!"
                                />
                                <flux:error name="localStartDate" class="app-error" />
                            </flux:field>

                            <flux:field>
                                <flux:label for="local-end-date" class="app-label!">
                                    <span aria-hidden="true" class="text-app-danger-ink me-1">*</span> {{ __('business.promotion.end_date') }}
                                </flux:label>
                                <flux:input
                                    id="local-end-date"
                                    wire:model="localEndDate"
                                    type="date"
                                    required
                                    min="{{ $minimumStartDate }}"
                                    input:class="app-input!"
                                />
                                <flux:error name="localEndDate" class="app-error" />
                            </flux:field>
                        </div>

                        <div class="app-note-with-icon text-app-ink-help">
                            <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                                <flux:icon.information-circle variant="outline" class="size-4" />
                            </span>
                            <p class="app-role-support!">{{ __('business.promotion.date_range_help') }}</p>
                        </div>
                    </div>
                </section>

                <section aria-labelledby="promotion-extra-points-heading" class="space-y-6 rounded-2xl border border-app-border bg-app-surface p-4 sm:p-6">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span class="app-role-support! font-semibold! text-app-accent-text">02</span>
                            <flux:heading level="2" id="promotion-extra-points-heading" class="app-role-section! text-app-ink!">
                                {{ __('business.promotion.extra_points_heading') }}
                            </flux:heading>
                            <flux:badge color="amber" class="app-role-support! font-medium! dark:bg-amber-400/30!">
                                {{ __('business.promotion.optional') }}
                            </flux:badge>
                        </div>
                        <flux:text class="app-role-support! text-app-ink-secondary!">
                            {{ __('business.promotion.extra_points_description') }}
                        </flux:text>
                    </div>

                    <div class="space-y-4">
                        <div class="grid min-w-0 items-start gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label for="extra-weekday" class="app-label!">{{ __('business.promotion.rule_weekday') }}</flux:label>
                                <flux:select id="extra-weekday" wire:model.live="draftWeekday" class="app-input!">
                                    <option value="">{{ __('business.promotion.choose_weekday') }}</option>
                                    @foreach ([1, 2, 3, 4, 5, 6, 7] as $weekday)
                                        <option value="{{ $weekday }}">{{ __('business.promotion.weekdays.'.$weekday) }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="draftWeekday" class="app-error" />
                            </flux:field>

                            <flux:field>
                                <flux:label for="extra-multiplier" class="app-label!">{{ __('business.promotion.rule_multiplier') }}</flux:label>
                                <flux:select id="extra-multiplier" wire:model.live="draftMultiplier" class="app-input!">
                                    @foreach ([2, 3, 5] as $multiplier)
                                        <option value="{{ $multiplier }}">{{ __('business.promotion.multiplier_option', ['multiplier' => $multiplier, 'points' => $multiplier]) }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="draftMultiplier" class="app-error" />
                            </flux:field>
                        </div>

                        <fieldset class="space-y-3">
                            <legend class="app-label! mb-2">{{ __('business.promotion.rule_schedule') }}</legend>
                            <div class="flex flex-wrap gap-x-6 gap-y-2">
                                <label class="app-focus inline-flex min-h-11 items-center gap-2 rounded-lg px-1 text-app-ink-secondary">
                                    <input
                                        type="radio"
                                        wire:model.live="draftMode"
                                        value="all_day"
                                        class="size-4 accent-app-accent"
                                    >
                                    <span>{{ __('business.promotion.all_day') }}</span>
                                </label>
                                <label class="app-focus inline-flex min-h-11 items-center gap-2 rounded-lg px-1 text-app-ink-secondary">
                                    <input
                                        type="radio"
                                        wire:model.live="draftMode"
                                        value="timed"
                                        class="size-4 accent-app-accent"
                                    >
                                    <span>{{ __('business.promotion.timed') }}</span>
                                </label>
                            </div>
                        </fieldset>

                        @if ($draftMode === 'timed')
                            <div class="grid min-w-0 items-start gap-4 sm:grid-cols-2">
                                <flux:field>
                                    <flux:label for="extra-start-time" class="app-label!">{{ __('business.promotion.start_time') }}</flux:label>
                                    <flux:input id="extra-start-time" wire:model.live.debounce.300ms="draftStartTime" type="time" input:class="app-input!" />
                                    <flux:error name="draftStartTime" class="app-error" />
                                </flux:field>
                                <flux:field>
                                    <flux:label for="extra-end-time" class="app-label!">{{ __('business.promotion.end_time') }}</flux:label>
                                    <flux:input id="extra-end-time" wire:model.live.debounce.300ms="draftEndTime" type="time" input:class="app-input!" />
                                    <flux:error name="draftEndTime" class="app-error" />
                                </flux:field>
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <flux:button
                                type="button"
                                wire:click="addExtraPoint"
                                wire:loading.attr="disabled"
                                wire:target="draftWeekday,draftMultiplier,draftMode,draftStartTime,draftEndTime,save"
                                variant="filled"
                                icon:leading="plus"
                                class="app-button-secondary min-h-11"
                            >
                                {{ __('business.promotion.add_rule') }}
                            </flux:button>
                        </div>

                        <flux:error name="extraPoints" class="app-error" />

                        @if ($extraPoints !== [])
                            <ul class="space-y-2" aria-label="{{ __('business.promotion.saved_rules') }}">
                                @foreach ($extraPoints as $index => $rule)
                                    <li wire:key="extra-point-{{ $rule['weekday'] }}-{{ $rule['start_time'] ?? 'all-day' }}" class="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-xl border border-app-border bg-app-control px-4 py-3">
                                        <span class="min-w-0">
                                            <span class="app-role-body! block font-semibold text-app-ink">{{ __('business.promotion.weekdays.'.$rule['weekday']) }}</span>
                                            <span class="app-role-support! block text-app-ink-secondary">{{ $rule['start_time'] === null ? __('business.promotion.all_day') : $rule['start_time'].'–'.$rule['end_time'] }}</span>
                                        </span>
                                        <div class="ms-auto flex min-w-0 flex-wrap items-center gap-3">
                                            <flux:badge color="violet" class="app-role-support! shrink-0 font-medium!">
                                                {{ __('business.promotion.points', ['points' => $rule['multiplier']]) }} · ×{{ $rule['multiplier'] }}
                                            </flux:badge>
                                            <flux:button
                                                type="button"
                                                wire:click="removeExtraPoint({{ $index }})"
                                                wire:loading.attr="disabled"
                                                wire:target="save"
                                                variant="ghost"
                                                class="app-button min-h-11 shrink-0 text-app-accent-text!"
                                                aria-label="{{ __('business.promotion.remove_rule', ['weekday' => __('business.promotion.weekdays.'.$rule['weekday'])]) }}"
                                            >
                                                {{ __('business.promotion.remove') }}
                                            </flux:button>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                    <div class="app-note-with-icon text-app-ink-help">
                        <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                            <flux:icon.information-circle variant="outline" class="size-4" />
                        </span>
                        <p class="app-role-support!">{{ __('business.promotion.window_help') }}</p>
                    </div>
                    </div>
                </section>

                <div class="border-t border-app-line pt-6">
                    <flux:error name="promotion" class="app-error" />
                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <flux:button
                            x-show="! hasUnsavedChanges()"
                            type="button"
                            wire:click="cancel"
                            wire:loading.attr="disabled"
                            wire:target="save"
                            variant="ghost"
                            class="app-button min-h-11"
                        >
                            {{ __('business.promotion.cancel') }}
                        </flux:button>
                        <flux:button
                            x-cloak
                            x-show="hasUnsavedChanges()"
                            x-on:click="$dispatch('modal-show', { name: 'discard-promotion-draft' })"
                            type="button"
                            wire:loading.attr="disabled"
                            wire:target="save"
                            variant="ghost"
                            class="app-button min-h-11"
                        >
                            {{ __('business.promotion.cancel') }}
                        </flux:button>

                        <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                            <flux:button
                                type="submit"
                                variant="filled"
                                class="app-button-secondary min-h-11"
                                wire:loading.attr="disabled"
                                wire:target="save"
                            >
                                {{ __('business.promotion.save_draft') }}
                            </flux:button>
                            <flux:button type="button" variant="primary" disabled class="app-button-primary min-h-11 opacity-60">
                                {{ __('business.promotion.review_publication') }}
                                <flux:icon.arrow-right variant="outline" class="ms-2 size-4" />
                            </flux:button>
                        </div>
                    </div>
                </div>
            </div>

            <aside
                class="min-w-0 space-y-4 lg:sticky lg:top-8 lg:self-start"
                aria-labelledby="promotion-summary-heading"
                data-test="promotion-summary"
                x-data="{
                    notDefined: @js(__('business.promotion.not_defined')),
                    datesNotDefined: @js(__('business.promotion.dates_not_defined')),
                    /** Format an ISO calendar date without converting it through the browser timezone.
                     * @param {string | null} date ISO calendar date, or an empty value when unset.
                     * @param {boolean} includeYear Whether to include the four-digit calendar year.
                     * @returns {string} Localized date text, or the configured not-defined placeholder for an unset date.
                     */
                    formatDate(date, includeYear = false) {
                        if (! date) return this.notDefined;

                        const [year, month, day] = date.split('-').map(Number);
                        const calendarDate = new Date(Date.UTC(year, month - 1, day, 12));
                        const options = { day: 'numeric', month: 'short', timeZone: 'UTC' };

                        if (includeYear) options.year = 'numeric';

                        return new Intl.DateTimeFormat('es', options).format(calendarDate).replace(/\./g, '');
                    },
                    /** Format the promotion's inclusive validity range from ISO calendar dates.
                     * @param {string | null} start ISO start date, or an empty value when unset.
                     * @param {string | null} end ISO end date, or an empty value when unset.
                     * @returns {string} Localized range with the year once for same-year dates, both years otherwise, or the dates-not-defined placeholder when either date is unset.
                     */
                    formatRange(start, end) {
                        if (! start || ! end) return this.datesNotDefined;

                        const startYear = start.slice(0, 4);
                        const endYear = end.slice(0, 4);

                        if (startYear === endYear) {
                            return `${this.formatDate(start)} – ${this.formatDate(end)} ${endYear}`;
                        }

                        return `${this.formatDate(start, true)} – ${this.formatDate(end, true)}`;
                    },
                }"
            >
                <section class="space-y-5 rounded-2xl border border-app-border bg-app-surface p-5 sm:p-6">
                    <div class="space-y-2">
                        <flux:heading level="2" id="promotion-summary-heading" class="app-role-card! text-app-ink!">
                            {{ __('business.promotion.summary_heading') }}
                        </flux:heading>
                        <p
                            class="border-s-2 border-app-accent ps-4 app-role-body! text-app-ink-secondary"
                            data-test="summary-reward-title"
                            x-text="$wire.rewardTitle || @js(__('business.promotion.summary_placeholder'))"
                        >
                            {{ $rewardTitle !== '' ? $rewardTitle : __('business.promotion.summary_placeholder') }}
                        </p>
                        <p
                            class="app-role-support! text-app-ink-help"
                            data-test="summary-reward-description"
                            x-show="$wire.rewardDescription !== ''"
                            x-text="$wire.rewardDescription"
                        >{{ $rewardDescription }}</p>
                    </div>

                    <dl class="space-y-3 border-t border-app-line pt-4">
                        <div class="flex items-start justify-between gap-4">
                            <dt class="app-role-support! text-app-ink-secondary">{{ __('business.promotion.summary_target') }}</dt>
                            <dd
                                class="app-role-action! text-end text-app-ink"
                                data-test="summary-target"
                                x-text="$wire.targetPoints !== '' ? `${$wire.targetPoints} puntos` : @js(__('business.promotion.not_defined'))"
                            >
                                {{ $targetPoints !== '' ? __('business.promotion.points', ['points' => $targetPoints]) : __('business.promotion.not_defined') }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="app-role-support! text-app-ink-secondary">{{ __('business.promotion.summary_regular_visit') }}</dt>
                            <dd class="app-role-action! text-end text-app-ink">{{ __('business.promotion.one_point') }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="app-role-support! text-app-ink-secondary">{{ __('business.promotion.summary_extra_points') }}</dt>
                            <dd class="app-role-action! text-end text-app-ink">
                                {{ $this->ruleSummary === [] ? __('business.promotion.no_extra_rules_short') : trans_choice('business.promotion.configuration_count', count($this->ruleSummary)) }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="app-role-support! text-app-ink-secondary">{{ __('business.promotion.summary_validity') }}</dt>
                            <dd
                                class="app-role-action! max-w-40 text-end text-app-ink"
                                data-test="summary-validity"
                                x-text="formatRange($wire.localStartDate, $wire.localEndDate)"
                            >
                                @if ($localStartDate !== '' && $localEndDate !== '')
                                    {{ $localStartDate }} – {{ $localEndDate }}
                                @else
                                    {{ __('business.promotion.dates_not_defined') }}
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if ($this->ruleSummary !== [])
                        <ul class="space-y-2 border-t border-app-line pt-4 app-role-support! text-app-ink-secondary">
                            @foreach ($this->ruleSummary as $rule)
                                <li>{{ $rule }}</li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <p class="flex items-start gap-2 rounded-xl border border-app-priority-border bg-app-emphasis p-4 app-role-support! text-app-ink-priority">
                    <flux:icon.lock-closed variant="outline" class="mt-0.5 size-4 shrink-0" />
                    <span>{{ __('business.promotion.publication_note') }}</span>
                </p>
            </aside>
        </div>
    </form>

    <flux:modal name="discard-promotion-draft" class="app-theme space-y-6">
        <div class="space-y-2">
            <flux:heading size="lg" class="app-role-section! text-app-ink!">{{ __('business.promotion.discard_title') }}</flux:heading>
            <flux:text class="app-role-support!">{{ __('business.promotion.discard_confirmation') }}</flux:text>
        </div>
        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <flux:modal.close class="w-full sm:w-auto">
                <flux:button
                    type="button"
                    wire:loading.attr="disabled"
                    wire:target="save"
                    class="app-button min-h-11 w-full sm:w-auto"
                >
                    {{ __('business.promotion.continue_editing') }}
                </flux:button>
            </flux:modal.close>
            <flux:button
                type="button"
                wire:click="cancel"
                wire:loading.attr="disabled"
                wire:target="save"
                variant="primary"
                class="app-button-primary min-h-11 w-full sm:w-auto"
            >
                {{ __('business.promotion.discard_changes') }}
            </flux:button>
        </div>
    </flux:modal>
</main>
