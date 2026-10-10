<main class="app-theme app-workspace wrap-anywhere">
    <header class="flex flex-wrap items-center justify-between gap-6">
        <div class="min-w-0 space-y-2">
            <p class="app-role-body! text-app-accent">
                {{ $business->name }} · {{ __('business.pass.business_label') }}
            </p>
            <flux:heading level="1" class="app-heading">
                {{ __('summary.title') }}
            </flux:heading>
            <flux:text class="app-role-intro! app-text-secondary max-w-3xl">
                {{ __('summary.description') }}
            </flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @if ($preparationCompleted < 2)
                <flux:badge color="amber" class="app-role-support! font-medium!">
                    {{ __('summary.preparation_pending') }}
                </flux:badge>
            @endif
            <flux:button :href="route('business.pass')" icon="credit-card" icon:variant="outline" wire:navigate class="app-button-secondary shrink-0">
                {{ __('summary.go_to_pass') }}
            </flux:button>
        </div>
    </header>

    @if ($statistics === 'unavailable')
        <div
            role="alert" aria-labelledby="summary-error" aria-describedby="summary-recovery"
            class="flex flex-wrap items-center justify-between gap-4 rounded-[20px] border border-app-warning-border bg-app-warning-surface p-4 text-app-warning-ink"
        >
            <div class="flex min-w-0 items-center gap-3">
                <flux:icon.information-circle variant="outline" aria-hidden="true" class="size-6 shrink-0" />
                <div class="min-w-0 space-y-1">
                    <p id="summary-error" class="app-role-body font-semibold">
                        {{ __('summary.load_error') }}
                    </p>
                    <p id="summary-recovery" class="app-role-support">
                        {{ __('summary.statistics_recovery') }}
                    </p>
                </div>
            </div>
            <flux:button wire:click="$refresh" wire:loading.attr="disabled" wire:target="$refresh" aria-describedby="summary-recovery" class="app-button-secondary shrink-0">
                <span wire:loading.remove wire:target="$refresh">
                    {{ __('summary.retry') }}
                </span>
                <span wire:loading wire:target="$refresh">
                    {{ __('summary.retrying') }}
                </span>
            </flux:button>
        </div>
    @endif

    <section
        aria-labelledby="promotion-title"
        @if (! $primaryPromotion || $primaryPromotion->reward_description) aria-describedby="promotion-description" @endif
        @class([
            'space-y-4 rounded-[20px] border p-4 sm:p-6',
            'border-app-priority-border bg-app-emphasis' => $primaryPhase === 'active',
            'border-app-border bg-app-surface' => $primaryPhase !== 'active',
        ])
    >
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading level="2" id="promotion-title" class="app-role-section! text-app-ink!">
                {{ __('summary.promotion') }}
            </flux:heading>
            @if ($primaryPromotion)
                <flux:badge :color="$primaryPhase === 'active' ? 'green' : 'blue'" class="app-role-support! font-medium!">
                    {{ __('business.pass.promotion_'.$primaryPhase.'_status') }}
                </flux:badge>
            @endif
        </div>
        <div class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-4">
            <span aria-hidden="true" @class([
                'flex size-12 shrink-0 items-center justify-center rounded-xl border',
                'border-app-priority-border bg-app-scheduled-surface text-app-ink-priority' => $primaryPromotion !== null,
                'border-app-border text-app-ink-secondary' => $primaryPromotion === null,
            ])>
                <flux:icon :name="$primaryPromotion ? 'gift' : 'megaphone'" variant="outline" class="size-6" />
            </span>
            <div class="flex min-w-0 flex-col gap-1">
                @if ($primaryPromotion)
                    <flux:heading level="3" class="app-role-card! text-app-ink!">
                        {{ $primaryPromotion->reward_title }}
                    </flux:heading>
                @else
                    <p class="app-role-body font-medium text-app-ink">
                        {{ __('summary.waiting') }}
                    </p>
                @endif
                @if ($primaryPromotion?->reward_description)
                    <p id="promotion-description" class="app-role-support! break-words text-app-ink-secondary!">
                        {{ $primaryPromotion->reward_description }}
                    </p>
                @elseif (! $primaryPromotion)
                    <div class="app-note-with-icon text-app-ink-help">
                        <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                            <flux:icon.information-circle variant="outline" class="size-4" />
                        </span>
                        <p id="promotion-description" class="app-role-support">
                            {{ __('summary.waiting_description') }}
                        </p>
                    </div>
                @endif
            </div>
            <dl class="col-start-2 flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:gap-x-8">
                <div class="min-w-0 space-y-1">
                    <dt class="app-role-support! flex items-center gap-2 font-medium! text-app-ink-secondary">
                        <flux:icon.trophy variant="outline" aria-hidden="true" class="size-5 shrink-0 text-app-accent" />
                        {{ __('business.promotion.review_goal') }}
                    </dt>
                    <dd class="app-role-body! break-words font-semibold! text-app-ink">
                        {{ $primaryPromotion ? __('summary.points', ['count' => $primaryPromotion->target_points]) : '—' }}
                    </dd>
                </div>
                <div class="min-w-0 space-y-1">
                    <dt class="app-role-support! flex items-center gap-2 font-medium! text-app-ink-secondary">
                        <flux:icon.calendar-days variant="outline" aria-hidden="true" class="size-5 shrink-0 text-app-accent" />
                        {{ __('business.promotion.review_validity') }}
                    </dt>
                    <dd class="app-role-body! break-words font-semibold! text-app-ink">
                        {{ $primaryPeriod ?? '—' }}
                    </dd>
                </div>
            </dl>
        </div>
        @if ($primaryPhase === 'scheduled')
            <div class="app-note-with-icon text-app-ink-help">
                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                    <flux:icon.information-circle variant="outline" class="size-4" />
                </span>
                <p class="app-role-support">
                    {{ __('summary.scheduled_waiting', ['date' => $primaryPromotion->starts_at->setTimezone($primaryPromotion->timezone_snapshot)->format('d/m/Y')]) }}
                </p>
            </div>
        @endif
    </section>

    <section aria-labelledby="activity-title" aria-describedby="activity-scope" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading level="2" id="activity-title" class="app-role-section! text-app-ink!">
                {{ __('summary.activity') }}
            </flux:heading>
            <div id="activity-scope" class="app-note-with-icon text-app-ink-help">
                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                    <flux:icon.information-circle variant="outline" class="size-4" />
                </span>
                <p class="app-role-support">
                    {{ __('summary.'.($currentPromotion ? 'activity_scope' : 'metrics_waiting')) }}
                </p>
            </div>
        </div>
        <dl
            @if ($statistics === 'unavailable') aria-describedby="summary-error summary-recovery" @endif
            class="grid grid-cols-1 gap-4 [@media(width>36rem)]:grid-cols-2 [@media(width>64rem)]:grid-cols-4"
        >
            @foreach (['active_passes' => 'credit-card', 'awarded_points' => 'star', 'unlocked_rewards' => 'gift', 'redeemed_rewards' => 'check-badge'] as $metric => $icon)
                <div wire:key="summary-metric-{{ $metric }}" class="flex min-w-0 flex-col gap-4 rounded-[20px] border border-app-border bg-app-surface p-4 sm:p-6">
                    <dt class="app-role-action flex min-h-12 items-start justify-between gap-2">
                        <span class="min-w-0">
                            {{ __('summary.'.$metric) }}
                        </span>
                        <flux:icon :name="$icon" variant="outline" aria-hidden="true" class="size-5 shrink-0 text-app-accent" />
                    </dt>
                    <dd class="app-role-metric">
                        {{ $statistics === 'available' ? \Illuminate\Support\Number::format($metrics[$metric], locale: app()->getLocale()) : '—' }}
                    </dd>
                    <dd class="app-role-support flex-1 space-y-2 text-app-ink-secondary">
                        <p>
                            {{ __('summary.'.$metric.'_description') }}
                        </p>
                        @if ($statistics === 'waiting' || ($statistics === 'available' && $metric === 'active_passes' && $metrics['active_passes'] === 0))
                            <div class="app-note-with-icon text-app-ink-help">
                                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                                    <flux:icon.information-circle variant="outline" class="size-4" />
                                </span>
                                <p class="app-role-support">
                                    {{ __('summary.'.($statistics === 'waiting' ? 'metrics_waiting' : 'empty_activity')) }}
                                </p>
                            </div>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </section>

    <div class="grid gap-6 [@media(width>48rem)]:grid-cols-2">
        @foreach (['points' => $primaryPromotion, 'next-promotion' => $upcomingPromotion] as $context => $contextPromotion)
            <section
                wire:key="summary-context-{{ $context }}"
                aria-labelledby="{{ $context }}-title"
                @if ($context === 'next-promotion' && (! $contextPromotion || $contextPromotion->reward_description)) aria-describedby="{{ $contextPromotion ? 'next-promotion-description' : 'next-promotion-empty' }}" @endif
                class="min-w-0 space-y-4 rounded-[20px] border border-app-border bg-app-surface p-4 sm:p-6"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading level="2" :id="$context.'-title'" class="app-role-section! text-app-ink!">
                        {{ __('summary.'.($context === 'points' ? 'promotion_points' : 'next')) }}
                    </flux:heading>
                    @if ($context === 'next-promotion' && $contextPromotion)
                        <flux:badge color="blue" class="app-role-support! font-medium!">
                            {{ __('business.pass.promotion_scheduled_status') }}
                        </flux:badge>
                    @endif
                </div>
                @if (! $contextPromotion)
                    <div class="app-note-with-icon text-app-ink-help">
                        <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                            <flux:icon.information-circle variant="outline" class="size-4" />
                        </span>
                        <p @if ($context === 'next-promotion') id="next-promotion-empty" @endif class="app-role-support">
                            {{ __('summary.'.($context === 'points' ? 'points_waiting' : 'next_empty')) }}
                        </p>
                    </div>
                @elseif ($context === 'points')
                    <dl>
                        <div class="flex justify-between gap-3">
                            <dt class="app-role-support! text-app-ink-secondary">
                                {{ __('business.promotion.summary_regular_visit') }}
                            </dt>
                            <dd class="app-role-action! text-end text-app-ink">
                                {{ __('business.promotion.one_point') }}
                            </dd>
                        </div>
                    </dl>
                @else
                    <div class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-4">
                        <span aria-hidden="true" class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-app-priority-border bg-app-scheduled-surface text-app-ink-priority">
                            <flux:icon.gift variant="outline" class="size-5" />
                        </span>
                        <div class="flex min-w-0 flex-col gap-1">
                            <flux:heading level="3" class="app-role-card! text-app-ink!">
                                {{ $contextPromotion->reward_title }}
                            </flux:heading>
                            @if ($contextPromotion->reward_description)
                                <p id="next-promotion-description" class="app-role-support! break-words text-app-ink-secondary!">
                                    {{ $contextPromotion->reward_description }}
                                </p>
                            @endif
                        </div>
                        <dl class="col-start-2 flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:gap-x-8">
                            <div class="min-w-0 space-y-1">
                                <dt class="app-role-support! flex items-center gap-2 font-medium! text-app-ink-secondary">
                                    <flux:icon.trophy variant="outline" aria-hidden="true" class="size-5 shrink-0 text-app-accent" />
                                    {{ __('business.promotion.review_goal') }}
                                </dt>
                                <dd class="app-role-body! break-words font-semibold! text-app-ink">
                                    {{ __('summary.points', ['count' => $contextPromotion->target_points]) }}
                                </dd>
                            </div>
                            <div class="min-w-0 space-y-1">
                                <dt class="app-role-support! flex items-center gap-2 font-medium! text-app-ink-secondary">
                                    <flux:icon.calendar-days variant="outline" aria-hidden="true" class="size-5 shrink-0 text-app-accent" />
                                    {{ __('business.promotion.review_validity') }}
                                </dt>
                                <dd class="app-role-body! break-words font-semibold! text-app-ink">
                                    {{ $nextPeriod }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                @endif
                @if ($contextPromotion)
                    @if ($contextPromotion->extraPoints->isEmpty())
                        <div class="app-note-with-icon border-t border-app-line pt-3 text-app-ink-help">
                            <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                                <flux:icon.information-circle variant="outline" class="size-4" />
                            </span>
                            <p class="app-role-support">
                                {{ __('business.promotion.review_no_extra_points') }}
                            </p>
                        </div>
                    @else
                        <section aria-label="{{ __('business.promotion.review_extra_points') }}" class="min-w-0 space-y-4 border-t border-app-line pt-3">
                            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                                <flux:icon.sparkles variant="outline" aria-hidden="true" class="size-5 shrink-0 text-app-accent" />
                                <flux:heading level="3" class="app-role-section! min-w-0 break-words text-app-ink!">
                                    {{ __('business.promotion.review_extra_points') }}
                                </flux:heading>
                                <p class="app-role-body! min-w-0 break-words font-semibold! text-app-ink">
                                    {{ trans_choice('business.promotion.configuration_count', $contextPromotion->extraPoints->count()) }}
                                </p>
                            </div>
                            <ul aria-label="{{ __('business.promotion.review_extra_points') }}" class="grid auto-rows-fr grid-cols-1 gap-2 [@media(width>36rem)]:grid-cols-2">
                                @foreach ($contextPromotion->extraPoints as $rule)
                                    <li wire:key="summary-{{ $context }}-extra-{{ $rule->id }}" class="flex min-h-16 min-w-0 items-center justify-between gap-2 rounded-lg border border-app-border bg-app-control px-3 py-2">
                                        <span class="app-role-support! min-w-0 break-words text-app-ink-secondary">
                                            {{ __('business.promotion.weekdays.'.$rule->weekday) }} · {{ $rule->start_time === null ? __('business.promotion.all_day') : substr($rule->start_time, 0, 5).'–'.substr($rule->end_time, 0, 5) }}
                                        </span>
                                        <flux:badge color="violet" class="app-role-support! shrink-0 font-medium!">
                                            ×{{ $rule->multiplier }}
                                        </flux:badge>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                @endif
            </section>
        @endforeach
    </div>

    @if ($preparationCompleted < 2)
        <section aria-labelledby="preparation-title" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <flux:heading level="2" id="preparation-title" class="app-role-section! text-app-ink!">
                    {{ __('summary.preparation') }}
                </flux:heading>
                <flux:badge :color="$preparationCompleted === 1 ? 'amber' : null" class="app-role-support! font-medium!">
                    {{ __('summary.completed_count', ['count' => $preparationCompleted]) }}
                </flux:badge>
            </div>
            <div class="grid gap-6 [@media(width>48rem)]:grid-cols-2">
                @foreach ($preparation as $step => $complete)
                    <article wire:key="preparation-{{ $step }}" aria-labelledby="preparation-{{ $step }}" class="min-w-0 space-y-4 rounded-[20px] border border-app-border bg-app-surface p-4 sm:p-6">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div class="flex min-w-0 items-center gap-4">
                                <span aria-hidden="true" class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-app-border text-app-ink-secondary">
                                    <flux:icon :name="$step === 'appearance' ? 'credit-card' : 'gift'" variant="outline" class="size-6" />
                                </span>
                                <flux:heading level="3" :id="'preparation-'.$step" class="app-role-card! min-w-0 text-app-ink!">
                                    {{ __('summary.'.$step.'_title') }}
                                </flux:heading>
                            </div>
                            <flux:badge :color="$complete ? 'green' : null" class="app-role-support! font-medium!">
                                {{ __('summary.'.($complete ? 'complete' : 'pending')) }}
                            </flux:badge>
                        </div>
                        <div class="app-note-with-icon text-app-ink-help">
                            <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                                <flux:icon.information-circle variant="outline" class="size-4" />
                            </span>
                            <p class="app-role-support">
                                {{ __('summary.'.($step === 'promotion' && $complete && ! $promotionPrepared ? 'promotion_draft' : $step.($complete ? '_complete' : '_pending'))) }}
                            </p>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</main>
