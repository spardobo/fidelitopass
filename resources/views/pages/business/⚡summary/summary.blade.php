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
        <section aria-labelledby="active-promotion-title" class="space-y-6 rounded-2xl border border-app-priority-border bg-app-emphasis p-6 sm:p-8">
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
                <article wire:key="preparation-{{ $step }}" aria-labelledby="preparation-{{ $step }}" class="min-w-0 space-y-4 rounded-2xl border border-app-border bg-app-surface p-6">
                    <div class="flex items-center justify-between gap-4">
                        <span aria-hidden="true" @class([
                            'flex size-10 items-center justify-center rounded-xl border',
                            'border-app-success-border bg-app-success-surface text-app-success-ink' => $prepared,
                            'border-app-border text-app-ink-secondary' => ! $prepared,
                        ])>
                            <flux:icon :name="$prepared ? 'check' : ($step === 'appearance' ? 'credit-card' : 'gift')" variant="outline" class="size-5" />
                        </span>
                        <flux:badge :class="$prepared ? 'border border-app-success-border bg-app-success-surface! text-app-success-ink!' : 'border border-app-border bg-app-surface! text-app-ink-secondary!'">
                            {{ __('summary.'.($prepared ? 'complete' : 'pending')) }}
                        </flux:badge>
                    </div>
                    <flux:heading level="3" id="preparation-{{ $step }}" class="app-role-card! text-app-ink!">
                        {{ __('summary.'.$step.'_title') }}
                    </flux:heading>
                    <p class="app-role-body text-app-ink-secondary">
                        {{ __('summary.'.$step.($prepared ? '_complete' : '_pending')) }}
                    </p>
                </article>
            @endforeach
        </div>
    </section>

    @if ($nextScheduled)
        <section aria-labelledby="next-promotion-title" class="space-y-4 rounded-2xl border border-app-border bg-app-surface p-6 sm:p-8">
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
        <section aria-labelledby="waiting-title" class="space-y-4 rounded-2xl border border-app-border bg-app-surface p-6 sm:p-8">
            <flux:heading level="2" id="waiting-title" class="app-role-section! text-app-ink!">
                {{ __('summary.'.($lastPromotion ? 'history_waiting' : 'waiting')) }}
            </flux:heading>
            <p class="app-role-body text-app-ink-secondary">
                {{ __('summary.'.($lastPromotion ? 'history_description' : 'waiting_description')) }}
            </p>
            <p class="app-role-support text-app-ink-help">
                {{ __('summary.waiting_counters') }}
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
