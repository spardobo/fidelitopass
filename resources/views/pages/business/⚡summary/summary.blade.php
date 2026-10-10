<main class="app-theme app-workspace wrap-anywhere">
    <header class="flex flex-wrap items-center justify-between gap-6">
        <div class="min-w-0 space-y-2">
            <p class="app-role-body text-app-accent-text">
                {{ $business->name }} · {{ __('business.pass.business_label') }}
            </p>
            <flux:heading level="1" class="app-heading">
                {{ __('summary.title') }}
            </flux:heading>
            <flux:text class="app-description max-w-3xl">
                {{ __('summary.description') }}
            </flux:text>
        </div>
        <flux:button :href="route('business.pass')" icon="credit-card" icon:variant="outline" wire:navigate class="app-button-secondary shrink-0">
            {{ __('summary.go_to_pass') }}
        </flux:button>
    </header>

    @if ($currentPromotion)
        <section aria-labelledby="active-promotion-title" class="space-y-6 rounded-[20px] border border-app-priority-border bg-app-emphasis p-6 sm:p-8">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="app-role-support font-medium text-app-ink-priority">
                    {{ __('summary.active') }}
                </p>
                <flux:badge icon="check" icon:variant="outline" class="border border-app-success-border bg-app-success-surface! text-app-success-ink!">
                    {{ __('summary.active_status') }}
                </flux:badge>
            </div>
            <div class="space-y-2">
                <flux:heading level="2" id="active-promotion-title" class="app-role-section! text-app-ink!">
                    {{ $currentPromotion->reward_title }}
                </flux:heading>
                @if ($currentPromotion->reward_description)
                    <p class="app-role-body text-app-ink-secondary">
                        {{ $currentPromotion->reward_description }}
                    </p>
                @endif
            </div>
            <dl class="grid gap-6 md:grid-cols-[1fr_1.5fr_1.5fr]">
                @foreach ([
                    'target' => __('summary.points', ['count' => $currentPromotion->target_points]),
                    'validity' => $activePeriod,
                    'timezone' => $currentPromotion->timezone_snapshot,
                ] as $label => $value)
                    <div wire:key="promotion-fact-{{ $label }}" class="min-w-0 space-y-2">
                        <dt class="app-role-support text-app-ink-priority">
                            {{ __('summary.'.$label) }}
                        </dt>
                        <dd class="app-role-body font-semibold">
                            {{ $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>
            <div class="space-y-2 border-t border-app-priority-border pt-4 text-app-ink-priority">
                @forelse ($currentPromotion->extraPoints as $rule)
                    <p wire:key="summary-extra-{{ $rule->id }}" class="app-role-support flex items-start gap-2">
                        <flux:icon.bolt variant="outline" aria-hidden="true" class="size-5 shrink-0" />
                        {{ __('summary.extra_points', [
                            'weekday' => __('business.promotion.weekdays.'.$rule->weekday),
                            'points' => $rule->multiplier,
                            'hours' => $rule->start_time === null
                                ? __('business.promotion.all_day')
                                : substr($rule->start_time, 0, 5).'–'.substr($rule->end_time, 0, 5),
                        ]) }}
                    </p>
                @empty
                    <p class="app-role-support">
                        {{ __('summary.regular_points') }}
                    </p>
                @endforelse
            </div>
        </section>

        <section aria-labelledby="activity-title" aria-describedby="activity-scope" class="space-y-4">
            <div class="space-y-1">
                <flux:heading level="2" id="activity-title" class="app-role-section! text-app-ink!">
                    {{ __('summary.activity') }}
                </flux:heading>
                <p id="activity-scope" class="app-role-support text-app-ink-secondary">
                    {{ __('summary.activity_scope') }}
                </p>
            </div>
            @if ($statistics === 'unavailable')
                <div role="status" class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-app-warning-border bg-app-warning-surface p-4 text-app-warning-ink">
                    <div class="min-w-0 space-y-1">
                        <p class="app-role-body font-semibold">
                            {{ __('summary.statistics_unavailable') }}
                        </p>
                        <p class="app-role-support">
                            {{ __('summary.statistics_recovery') }}
                        </p>
                    </div>
                    <flux:button wire:click="$refresh" wire:loading.attr="disabled" wire:target="$refresh" class="app-button-secondary">
                        <span wire:loading.remove wire:target="$refresh">
                            {{ __('summary.retry') }}
                        </span>
                        <span wire:loading wire:target="$refresh">
                            {{ __('summary.retrying') }}
                        </span>
                    </flux:button>
                </div>
            @endif
            <dl class="grid grid-cols-1 gap-4 [@media(width>36rem)]:grid-cols-2 [@media(width>64rem)]:grid-cols-4">
                @foreach (['active_passes' => 'credit-card', 'awarded_points' => 'bolt', 'unlocked_rewards' => 'gift', 'redeemed_rewards' => 'check-badge'] as $metric => $icon)
                    <div wire:key="summary-metric-{{ $metric }}" class="flex min-w-0 flex-col gap-4 rounded-[20px] border border-app-border bg-app-surface p-6">
                        <dt class="app-role-action flex flex-1 items-start justify-between gap-2">
                            {{ __('summary.'.$metric) }}
                            <flux:icon :name="$icon" variant="outline" aria-hidden="true" class="size-5 shrink-0 text-app-ink-help" />
                        </dt>
                        <dd class="app-role-metric">
                            {{ $statistics === 'available' ? \Illuminate\Support\Number::format($metrics[$metric], locale: app()->getLocale()) : '—' }}
                        </dd>
                        <dd class="app-role-support text-app-ink-secondary">
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

        @if ($nextScheduled)
            <section aria-label="{{ __('summary.next') }}" class="flex flex-wrap gap-x-6 gap-y-2 border-y border-app-line py-4">
                <p class="app-role-support text-app-ink-help">
                    {{ __('summary.next') }}
                </p>
                <div class="min-w-0 space-y-1">
                    <p class="app-role-body font-semibold">
                        {{ $nextScheduled->reward_title }}
                    </p>
                    <p class="app-role-support text-app-ink-secondary">
                        {{ __('summary.scheduled') }} · {{ $nextPeriod }}
                    </p>
                </div>
            </section>
        @endif
    @endif

    <section aria-labelledby="preparation-title" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="space-y-1">
                <flux:heading level="2" id="preparation-title" class="app-role-section! text-app-ink!">
                    {{ __('summary.preparation') }}
                </flux:heading>
                <p class="app-role-body text-app-ink-secondary">
                    {{ __('summary.preparation_description') }}
                </p>
            </div>
            <flux:badge class="border border-app-border bg-app-surface! text-app-ink-secondary!">
                {{ __('summary.completed_count', ['count' => (int) $appearancePrepared + (int) $promotionPrepared]) }}
            </flux:badge>
        </div>
        <div class="grid gap-6 [@media(width>48rem)]:grid-cols-2">
            @foreach ($preparation as $step => $prepared)
                @php($draft = $step === 'promotion' && ! $prepared && $hasPromotionDraft)
                <article wire:key="preparation-{{ $step }}" aria-labelledby="preparation-{{ $step }}" class="min-w-0 space-y-4 rounded-[20px] border border-app-border bg-app-surface p-6">
                    <div class="flex items-center justify-between gap-4">
                        <span aria-hidden="true" @class([
                            'flex size-10 items-center justify-center rounded-xl border',
                            'border-app-success-border bg-app-success-surface text-app-success-ink' => $prepared,
                            'border-app-border text-app-ink-secondary' => ! $prepared,
                        ])>
                            <flux:icon :name="$prepared ? 'check' : ($step === 'appearance' ? 'credit-card' : 'gift')" variant="outline" class="size-5" />
                        </span>
                        <flux:badge @class([
                            'border',
                            'border-app-success-border bg-app-success-surface! text-app-success-ink!' => $prepared,
                            'border-app-warning-border bg-app-warning-surface! text-app-warning-ink!' => $draft,
                            'border-app-border bg-app-surface! text-app-ink-secondary!' => ! $prepared && ! $draft,
                        ])>
                            {{ __('summary.'.($prepared ? 'complete' : ($draft ? 'draft' : 'pending'))) }}
                        </flux:badge>
                    </div>
                    <flux:heading level="3" id="preparation-{{ $step }}" class="app-role-card! text-app-ink!">
                        {{ __('summary.'.$step.'_title') }}
                    </flux:heading>
                    <p class="app-role-body text-app-ink-secondary">
                        {{ __('summary.'.$step.($prepared ? '_complete' : ($draft ? '_draft' : '_pending'))) }}
                    </p>
                </article>
            @endforeach
        </div>
    </section>

    @if (! $currentPromotion && $nextScheduled)
        <section aria-labelledby="next-promotion-title" class="space-y-4 rounded-[20px] border border-app-border bg-app-surface p-6 sm:p-8">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="app-role-support text-app-accent-text">
                    {{ __('summary.next') }}
                </p>
                <flux:badge icon="clock" icon:variant="outline" class="border border-app-priority-border bg-app-scheduled-surface! text-app-ink-priority!">
                    {{ __('summary.scheduled') }}
                </flux:badge>
            </div>
            <flux:heading level="2" id="next-promotion-title" class="app-role-section! text-app-ink!">
                {{ $nextScheduled->reward_title }}
            </flux:heading>
            <p class="app-role-body text-app-ink-secondary">
                {{ __('summary.scheduled_waiting', ['date' => $nextScheduled->starts_at->setTimezone($nextScheduled->timezone_snapshot)->format('d/m/Y')]) }}
            </p>
            <p class="app-role-support text-app-ink-secondary">
                {{ __('summary.target') }}: {{ __('summary.points', ['count' => $nextScheduled->target_points]) }} · {{ $nextPeriod }} · {{ $nextScheduled->timezone_snapshot }}
            </p>
        </section>
    @elseif (! $currentPromotion)
        <section aria-labelledby="waiting-title" class="space-y-4 rounded-[20px] border border-app-border bg-app-surface p-6 sm:p-8">
            <flux:heading level="2" id="waiting-title" class="app-role-section! text-app-ink!">
                {{ __('summary.'.($lastPromotion ? 'history_waiting' : 'waiting')) }}
            </flux:heading>
            <p class="app-role-body text-app-ink-secondary">
                {{ __('summary.'.($lastPromotion ? 'history_description' : 'waiting_description')) }}
            </p>
            @if ($lastPromotion)
                <div class="flex flex-wrap items-center gap-3 border-t border-app-line pt-4">
                    <p class="app-role-support text-app-ink-help">
                        {{ __('summary.last') }}
                    </p>
                    <p class="app-role-body font-semibold">
                        {{ $lastPromotion->reward_title }}
                    </p>
                    <flux:badge :class="$lastPromotion->phase === 'cancelled' ? 'border border-app-danger-border bg-app-danger-surface! text-app-danger-ink!' : 'border border-app-border bg-app-surface! text-app-ink-secondary!'">
                        {{ __('summary.'.$lastPromotion->phase) }}
                    </flux:badge>
                </div>
            @endif
        </section>
    @endif
</main>
