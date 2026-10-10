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
        <flux:button :href="route('business.pass')" icon="credit-card" icon:variant="outline" wire:navigate class="app-button-secondary shrink-0">
            {{ __('summary.go_to_pass') }}
        </flux:button>
    </header>

    @if ($currentPromotion)
        <section
            aria-labelledby="active-promotion-title"
            @if ($currentPromotion->reward_description) aria-describedby="active-promotion-description" @endif
            class="space-y-4 rounded-[20px] border border-app-priority-border bg-app-emphasis p-6"
        >
            <div class="flex flex-wrap items-center gap-3">
                <p class="app-role-support font-medium text-app-ink-priority">
                    {{ __('summary.active') }}
                </p>
                <flux:badge color="green" class="app-role-support! font-medium!">
                    {{ __('business.pass.promotion_active_status') }}
                </flux:badge>
            </div>
            <div class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-4">
                <span aria-hidden="true" class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-app-priority-border bg-app-scheduled-surface text-app-ink-priority">
                    <flux:icon.gift variant="outline" class="size-6" />
                </span>
                <div class="flex min-w-0 flex-col gap-1">
                    <flux:heading level="2" id="active-promotion-title" class="app-role-section! text-app-ink!">
                        {{ $currentPromotion->reward_title }}
                    </flux:heading>
                    @if ($currentPromotion->reward_description)
                        <p id="active-promotion-description" class="app-role-support! break-words text-app-ink-secondary!">
                            {{ $currentPromotion->reward_description }}
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
                            {{ __('summary.points', ['count' => $currentPromotion->target_points]) }}
                        </dd>
                    </div>
                    <div class="min-w-0 space-y-1">
                        <dt class="app-role-support! flex items-center gap-2 font-medium! text-app-ink-secondary">
                            <flux:icon.calendar-days variant="outline" aria-hidden="true" class="size-5 shrink-0 text-app-accent" />
                            {{ __('business.promotion.review_validity') }}
                        </dt>
                        <dd class="app-role-body! break-words font-semibold! text-app-ink">
                            {{ $activePeriod }}
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        <section aria-labelledby="activity-title" aria-describedby="activity-scope" class="space-y-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading level="2" id="activity-title" class="app-role-section! text-app-ink!">
                    {{ __('summary.activity') }}
                </flux:heading>
                <p id="activity-scope" class="app-role-support text-app-ink-secondary">
                    {{ __('summary.activity_scope') }}
                </p>
            </div>
            @if ($statistics === 'unavailable')
                <div
                    role="status" aria-labelledby="statistics-error" aria-describedby="statistics-recovery"
                    class="flex flex-wrap items-center justify-between gap-4 rounded-[20px] border border-app-warning-border bg-app-warning-surface p-4 text-app-warning-ink"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <flux:icon.information-circle variant="outline" aria-hidden="true" class="size-6 shrink-0" />
                        <div class="min-w-0 space-y-1">
                            <p id="statistics-error" class="app-role-body font-semibold">
                                {{ __('summary.statistics_unavailable') }}
                            </p>
                            <p id="statistics-recovery" class="app-role-support">
                                {{ __('summary.statistics_recovery') }}
                            </p>
                        </div>
                    </div>
                    <flux:button
                        wire:click="$refresh" wire:loading.attr="disabled" wire:target="$refresh"
                        aria-describedby="statistics-recovery" class="app-button-secondary shrink-0"
                    >
                        <span wire:loading.remove wire:target="$refresh">
                            {{ __('summary.retry') }}
                        </span>
                        <span wire:loading wire:target="$refresh">
                            {{ __('summary.retrying') }}
                        </span>
                    </flux:button>
                </div>
            @endif
            <dl
                @if ($statistics === 'unavailable') aria-describedby="statistics-error statistics-recovery" @endif
                class="grid grid-cols-1 gap-4 [@media(width>36rem)]:grid-cols-2 [@media(width>64rem)]:grid-cols-4"
            >
                @foreach (['active_passes' => 'credit-card', 'awarded_points' => 'bolt', 'unlocked_rewards' => 'gift', 'redeemed_rewards' => 'check-badge'] as $metric => $icon)
                    <div wire:key="summary-metric-{{ $metric }}" class="flex min-w-0 flex-col gap-4 rounded-[20px] border border-app-border bg-app-surface p-6">
                        <dt class="app-role-action flex min-h-12 items-start justify-between gap-2">
                            <span class="min-w-0">
                                {{ __('summary.'.$metric) }}
                            </span>
                            <flux:icon :name="$icon" variant="outline" aria-hidden="true" class="size-5 shrink-0 text-app-ink-help" />
                        </dt>
                        <dd class="app-role-metric">
                            {{ $statistics === 'available' ? \Illuminate\Support\Number::format($metrics[$metric], locale: app()->getLocale()) : '—' }}
                        </dd>
                        <dd class="app-role-support flex-1 text-app-ink-secondary">
                            {{ __('summary.'.$metric.'_description') }}
                        </dd>
                    </div>
                @endforeach
            </dl>
            @if ($statistics === 'available' && $metrics['active_passes'] === 0)
                <p class="app-role-body text-app-ink-secondary">
                    {{ __('summary.empty_activity') }}
                </p>
            @endif
        </section>

        <div @class(['grid gap-6', '[@media(width>48rem)]:grid-cols-2' => $nextScheduled !== null])>
            <section aria-labelledby="points-title" class="min-w-0 space-y-4 rounded-[20px] border border-app-border bg-app-surface p-6">
                <flux:heading level="2" id="points-title" class="app-role-section! text-app-ink!">
                    {{ __('summary.promotion_points') }}
                </flux:heading>
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
                @if ($currentPromotion->extraPoints->isEmpty())
                    <p class="app-role-support! border-t border-app-line pt-3 text-app-ink-secondary">
                        {{ __('business.promotion.review_no_extra_points') }}
                    </p>
                @else
                    <section aria-label="{{ __('business.promotion.review_extra_points') }}" class="min-w-0 space-y-3 border-t border-app-line pt-3">
                        <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="flex items-center justify-center" aria-hidden="true">
                                <flux:icon.sparkles variant="outline" class="size-5 shrink-0 text-app-accent" />
                            </span>
                            <p class="app-role-support! min-w-0 break-words font-medium! text-app-ink-secondary">
                                {{ __('business.promotion.review_extra_points') }}
                            </p>
                            <p class="app-role-body! min-w-0 break-words font-semibold! text-app-ink">
                                {{ trans_choice('business.promotion.configuration_count', $currentPromotion->extraPoints->count()) }}
                            </p>
                        </div>
                        <ul aria-label="{{ __('business.promotion.review_extra_points') }}" class="flex flex-wrap gap-2">
                            @foreach ($currentPromotion->extraPoints as $rule)
                                <li wire:key="summary-extra-{{ $rule->id }}" class="flex min-w-0 grow flex-wrap items-center justify-between gap-2 rounded-lg border border-app-border bg-app-control px-3 py-2">
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
            </section>
            @if ($nextScheduled)
                <section aria-labelledby="next-promotion-title" @if ($nextScheduled->reward_description) aria-describedby="next-promotion-description" @endif class="min-w-0 space-y-4 rounded-[20px] border border-app-border bg-app-surface p-6">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading level="2" id="next-promotion-title" class="app-role-section! text-app-ink!">
                            {{ __('summary.next') }}
                        </flux:heading>
                        <flux:badge color="blue" class="app-role-support! font-medium!">
                            {{ __('business.pass.promotion_scheduled_status') }}
                        </flux:badge>
                    </div>
                    <div class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-4">
                        <span aria-hidden="true" class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-app-priority-border bg-app-scheduled-surface text-app-ink-priority">
                            <flux:icon.gift variant="outline" class="size-5" />
                        </span>
                        <div class="flex min-w-0 flex-col gap-1">
                            <flux:heading level="3" class="app-role-card! text-app-ink!">
                                {{ $nextScheduled->reward_title }}
                            </flux:heading>
                            @if ($nextScheduled->reward_description)
                                <p id="next-promotion-description" class="app-role-support! break-words text-app-ink-secondary!">
                                    {{ $nextScheduled->reward_description }}
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
                                    {{ __('summary.points', ['count' => $nextScheduled->target_points]) }}
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
                    @if ($nextScheduled->extraPoints->isEmpty())
                        <p class="app-role-support! border-t border-app-line pt-3 text-app-ink-secondary">
                            {{ __('business.promotion.review_no_extra_points') }}
                        </p>
                    @else
                        <section aria-label="{{ __('business.promotion.review_extra_points') }}" class="min-w-0 space-y-3 border-t border-app-line pt-3">
                            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="flex items-center justify-center" aria-hidden="true">
                                    <flux:icon.sparkles variant="outline" class="size-5 shrink-0 text-app-accent" />
                                </span>
                                <p class="app-role-support! min-w-0 break-words font-medium! text-app-ink-secondary">
                                    {{ __('business.promotion.review_extra_points') }}
                                </p>
                                <p class="app-role-body! min-w-0 break-words font-semibold! text-app-ink">
                                    {{ trans_choice('business.promotion.configuration_count', $nextScheduled->extraPoints->count()) }}
                                </p>
                            </div>
                            <ul aria-label="{{ __('business.promotion.review_extra_points') }}" class="flex flex-wrap gap-2">
                                @foreach ($nextScheduled->extraPoints as $rule)
                                    <li wire:key="summary-next-extra-{{ $rule->id }}" class="flex min-w-0 grow flex-wrap items-center justify-between gap-2 rounded-lg border border-app-border bg-app-control px-3 py-2">
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
                </section>
            @endif
        </div>
    @endif

    <section aria-labelledby="preparation-title" @class(['space-y-4' => $currentPromotion !== null, 'space-y-6' => $currentPromotion === null])>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="space-y-1">
                <flux:heading level="2" id="preparation-title" class="app-role-section! text-app-ink!">
                    {{ __('summary.preparation') }}
                </flux:heading>
            </div>
            <flux:badge class="border border-app-border bg-app-surface! text-app-ink-secondary!">
                {{ __('summary.completed_count', ['count' => (int) $appearancePrepared + (int) $promotionPrepared]) }}
            </flux:badge>
        </div>
        <div @class(['grid [@media(width>48rem)]:grid-cols-2', 'gap-4' => $currentPromotion !== null, 'gap-6' => $currentPromotion === null])>
            @foreach ($preparation as $step => $prepared)
                @php($draft = $step === 'promotion' && ! $prepared && $hasPromotionDraft)
                <article wire:key="preparation-{{ $step }}" aria-labelledby="preparation-{{ $step }}" @class([
                    'min-w-0 rounded-[20px] border border-app-border bg-app-surface',
                    'space-y-3 p-4' => $currentPromotion !== null,
                    'space-y-4 p-6' => $currentPromotion === null,
                ])>
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-4">
                            <span aria-hidden="true" @class([
                                'flex shrink-0 items-center justify-center rounded-xl border',
                                'size-10' => $currentPromotion !== null,
                                'size-12' => $currentPromotion === null,
                                'border-app-success-border bg-app-success-surface text-app-success-ink' => $prepared,
                                'border-app-border text-app-ink-secondary' => ! $prepared,
                            ])>
                                <flux:icon :name="$prepared ? 'check' : ($step === 'appearance' ? 'credit-card' : 'gift')" variant="outline" :class="$currentPromotion ? 'size-5' : 'size-6'" />
                            </span>
                            <flux:heading level="3" id="preparation-{{ $step }}" class="app-role-card! min-w-0 text-app-ink!">
                                {{ __('summary.'.$step.'_title') }}
                            </flux:heading>
                        </div>
                        <flux:badge :color="$draft ? 'amber' : null" @class([
                            'app-role-support! font-medium!',
                            'border' => ! $draft,
                            'border-app-success-border bg-app-success-surface! text-app-success-ink!' => $prepared,
                            'border-app-border bg-app-surface! text-app-ink-secondary!' => ! $prepared && ! $draft,
                        ])>
                            {{ $draft ? __('business.pass.promotion_draft_status') : __('summary.'.($prepared ? 'complete' : 'pending')) }}
                        </flux:badge>
                    </div>
                    <p @class([
                        'text-app-ink-secondary',
                        'app-role-support' => $currentPromotion !== null && $prepared,
                        'app-role-body' => $currentPromotion === null || ! $prepared,
                    ])>
                        {{ __('summary.'.$step.($prepared ? '_complete' : ($draft ? '_draft' : '_pending'))) }}
                    </p>
                </article>
            @endforeach
        </div>
    </section>

    @if (! $currentPromotion && $nextScheduled)
        <section aria-labelledby="next-promotion-title" aria-describedby="{{ $nextScheduled->reward_description ? 'next-promotion-description scheduled-waiting' : 'scheduled-waiting' }}" class="space-y-6 rounded-[20px] border border-app-border bg-app-surface p-6">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading level="2" id="next-promotion-title" class="app-role-section! text-app-ink!">
                    {{ __('summary.next') }}
                </flux:heading>
                <flux:badge color="blue" class="app-role-support! font-medium!">
                    {{ __('business.pass.promotion_scheduled_status') }}
                </flux:badge>
            </div>
            <div class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-4">
                <span aria-hidden="true" class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-app-priority-border bg-app-scheduled-surface text-app-ink-priority">
                    <flux:icon.gift variant="outline" class="size-6" />
                </span>
                <div class="flex min-w-0 flex-col gap-1">
                    <flux:heading level="3" class="app-role-card! text-app-ink!">
                        {{ $nextScheduled->reward_title }}
                    </flux:heading>
                    @if ($nextScheduled->reward_description)
                        <p id="next-promotion-description" class="app-role-support! break-words text-app-ink-secondary!">
                            {{ $nextScheduled->reward_description }}
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
                            {{ __('summary.points', ['count' => $nextScheduled->target_points]) }}
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
            @if ($nextScheduled->extraPoints->isEmpty())
                <p class="app-role-support! border-t border-app-line pt-3 text-app-ink-secondary">
                    {{ __('business.promotion.review_no_extra_points') }}
                </p>
            @else
                <section aria-label="{{ __('business.promotion.review_extra_points') }}" class="min-w-0 space-y-3 border-t border-app-line pt-3">
                    <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="flex items-center justify-center" aria-hidden="true">
                            <flux:icon.sparkles variant="outline" class="size-5 shrink-0 text-app-accent" />
                        </span>
                        <p class="app-role-support! min-w-0 break-words font-medium! text-app-ink-secondary">
                            {{ __('business.promotion.review_extra_points') }}
                        </p>
                        <p class="app-role-body! min-w-0 break-words font-semibold! text-app-ink">
                            {{ trans_choice('business.promotion.configuration_count', $nextScheduled->extraPoints->count()) }}
                        </p>
                    </div>
                    <ul aria-label="{{ __('business.promotion.review_extra_points') }}" class="flex flex-wrap gap-2">
                        @foreach ($nextScheduled->extraPoints as $rule)
                            <li wire:key="summary-next-extra-{{ $rule->id }}" class="flex min-w-0 grow flex-wrap items-center justify-between gap-2 rounded-lg border border-app-border bg-app-control px-3 py-2">
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
            <div id="scheduled-waiting" class="app-note-with-icon text-app-ink-help">
                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                    <flux:icon.information-circle variant="outline" class="size-4" />
                </span>
                <p class="app-role-support!">
                    {{ __('summary.scheduled_waiting', ['date' => $nextScheduled->starts_at->setTimezone($nextScheduled->timezone_snapshot)->format('d/m/Y')]) }}
                </p>
            </div>
        </section>
    @elseif (! $currentPromotion)
        <section aria-labelledby="waiting-title" aria-describedby="waiting-description" class="space-y-6 rounded-[20px] border border-app-border bg-app-surface p-6">
            <div class="flex items-start gap-4">
                <span aria-hidden="true" class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-app-border text-app-ink-secondary">
                    <flux:icon.clock variant="outline" aria-hidden="true" class="size-6" />
                </span>
                <div class="min-w-0 space-y-2">
                    <flux:heading level="2" id="waiting-title" class="app-role-section! text-app-ink!">
                        {{ __('summary.'.($lastPromotion ? 'history_waiting' : 'waiting')) }}
                    </flux:heading>
                    <p id="waiting-description" class="app-role-body text-app-ink-secondary">
                        {{ __('summary.'.($lastPromotion ? 'history_description' : 'waiting_description')) }}
                    </p>
                </div>
            </div>
            @if ($lastPromotion)
                <div class="flex flex-wrap items-center justify-between gap-4 border-t border-app-line pt-4">
                    <div class="min-w-0 space-y-1">
                        <p class="app-role-support text-app-ink-help">
                            {{ __('summary.last') }}
                        </p>
                        <p class="app-role-body font-semibold">
                            {{ $lastPromotion->reward_title }}
                        </p>
                    </div>
                    <flux:badge :color="$lastPromotion->phase === 'cancelled' ? 'red' : null" class="app-role-support! font-medium!">
                        {{ __('business.pass.promotion_'.$lastPromotion->phase.'_status') }}
                    </flux:badge>
                </div>
            @endif
        </section>
    @endif
</main>
