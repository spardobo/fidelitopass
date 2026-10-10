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
            <flux:button :href="route('business.pass')" icon:trailing="arrow-right" icon:variant="outline" wire:navigate variant="primary" class="app-button-primary shrink-0">
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
        <x-summary-promotion-card :promotion="$primaryPromotion" :period="$primaryPeriod" :phase="$primaryPhase" />
        @if ($primaryPhase === 'scheduled')
            <div class="app-note-with-icon text-app-ink-help">
                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                    <flux:icon.information-circle variant="outline" class="size-4" />
                </span>
                <p
                    x-data x-text="@js(__('summary.scheduled_waiting')).replace(':date', $regionalDates.date(@js($primaryPeriod['start'])))"
                    class="app-role-support"
                >
                    @php($waitingText = explode(':date', __('summary.scheduled_waiting'), 2))
                    {{ $waitingText[0] }}<x-regional-date :date="$primaryPeriod['start']" />{{ $waitingText[1] }}
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
            @foreach (['active_passes' => 'credit-card', 'returning_passes' => 'arrow-path-rounded-square', 'unlocked_rewards' => 'gift', 'redeemed_rewards' => 'check-badge'] as $metric => $icon)
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
                @if ($context === 'next-promotion' && $contextPromotion)
                    <x-summary-promotion-card :promotion="$contextPromotion" :period="$nextPeriod" phase="scheduled" context="upcoming" />
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading level="2" :id="$context.'-title'" class="app-role-section! text-app-ink!">
                            {{ __('summary.'.($context === 'points' ? 'promotion_points' : 'next')) }}
                        </flux:heading>
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
                    @endif
                @endif
                @if ($context === 'points' && $contextPromotion)
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
                            <div class="grid min-h-11 grid-cols-[1.25rem_minmax(0,1fr)] items-center gap-x-2 gap-y-1 sm:grid-cols-[1.5rem_8rem_minmax(0,1fr)] sm:gap-x-3 sm:gap-y-0">
                                <span aria-hidden="true" class="col-start-1 row-start-1 flex items-center justify-center">
                                    <flux:icon.sparkles variant="outline" class="size-5 shrink-0 text-app-accent" />
                                </span>
                                <span class="app-role-support! col-start-2 min-w-0 break-words font-medium! text-app-ink-secondary sm:col-start-2 sm:row-start-1">
                                    {{ __('business.promotion.review_extra_points') }}
                                </span>
                                <span class="app-role-body! col-start-2 min-w-0 break-words font-semibold! text-app-ink sm:col-start-3 sm:row-start-1">
                                    {{ trans_choice('business.promotion.configuration_count', $contextPromotion->extraPoints->count()) }}
                                </span>
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

    <section aria-labelledby="promotion-history-heading" class="space-y-4">
        <flux:heading level="2" id="promotion-history-heading" class="app-role-section! text-app-ink!">
            {{ __('summary.history_heading', ['count' => $historyPromotions->total()]) }}
        </flux:heading>
        @if ($historyPromotions->total() > 0)
            <ul aria-label="{{ __('summary.history_heading', ['count' => $historyPromotions->total()]) }}" class="divide-y divide-app-line">
                @foreach ($historyPromotions as $historicalPromotion)
                    <li
                        wire:key="promotion-history-{{ $historicalPromotion['promotion']->public_id }}"
                        data-promotion-phase="{{ $historicalPromotion['phase'] }}"
                        data-promotion-public-id="{{ $historicalPromotion['promotion']->public_id }}"
                        class="flex min-w-0 flex-col gap-2 py-2 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:heading level="3" size="base" class="app-role-support! min-w-0 break-words font-medium! text-app-ink!">
                                    {{ $historicalPromotion['promotion']->reward_title }}
                                </flux:heading>
                                <flux:badge :color="$historicalPromotion['phase'] === 'cancelled' ? 'red' : null" size="sm">
                                    {{ __('business.pass.promotion_'.$historicalPromotion['phase'].'_status') }}
                                </flux:badge>
                            </div>
                            <flux:text class="app-role-support! text-app-ink-secondary!">
                                <x-regional-date :date="$historicalPromotion['period']['start']" :end-date="$historicalPromotion['period']['end']" />
                            </flux:text>
                        </div>
                        <button
                            id="summary-history-detail-trigger-{{ $historicalPromotion['promotion']->public_id }}"
                            type="button"
                            wire:click="showPromotionDetail('{{ $historicalPromotion['promotion']->public_id }}', 'history')"
                            wire:loading.attr="disabled"
                            wire:target="showPromotionDetail"
                            class="app-control! app-role-support! app-focus! inline-flex shrink-0 cursor-pointer items-center self-start bg-transparent font-medium! text-app-accent-text! underline-offset-4 hover:underline focus-visible:underline disabled:pointer-events-none disabled:cursor-default disabled:opacity-50 sm:self-center"
                        >
                            {{ __('business.pass.view_promotion_detail') }}
                        </button>
                    </li>
                @endforeach
            </ul>
            @if ($historyPromotions->hasPages())
                <flux:pagination :paginator="$historyPromotions" />
            @endif
        @else
            <div class="app-note-with-icon text-app-ink-help">
                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                    <flux:icon.information-circle variant="outline" class="size-4" />
                </span>
                <p class="app-role-support">
                    {{ __('summary.history_empty') }}
                </p>
            </div>
        @endif
    </section>

    <x-promotion-detail
        :detail="$this->promotionDetail"
        :close-label="__('summary.close_promotion_detail')"
        focus-fallback="promotion-title"
        :focus-origin-prefix="'summary-'.$promotionDetailOrigin.'-detail-trigger-'"
    />
</main>
