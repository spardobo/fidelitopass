@props(['promotion' => null, 'points' => null, 'confirmed' => false])

<div data-visit-points-card @class([
    'space-y-4 rounded-2xl border p-4',
    'border-app-priority-border bg-app-emphasis' => ! $confirmed,
    'border-app-success-border bg-app-success-surface' => $confirmed,
])>
    @if ($promotion)
        <div id="visit-reward" class="flex items-center gap-3">
            <flux:icon.gift variant="outline" class="size-8 shrink-0 text-app-accent" />
            <div class="min-w-0 space-y-1 break-words">
                <h4 class="app-role-card text-app-ink">{{ $promotion['reward_title'] }}</h4>
                @if ($promotion['reward_description'] !== null && $promotion['reward_description'] !== '')
                    <p class="app-role-support text-app-ink-secondary">{{ $promotion['reward_description'] }}</p>
                @endif
            </div>
        </div>
    @endif
    <dl class="grid grid-cols-2 gap-4">
        @if ($points !== null)
            <div class="flex flex-col justify-center gap-1">
                <dt class="app-role-support text-app-ink-secondary">
                    {{ __($confirmed ? 'visit-registration.awarded' : 'visit-registration.visit_points') }}
                </dt>
                <dd id="visit-points-gain" class="app-role-metric text-app-ink">
                    +{{ $points }} <span class="app-role-support text-app-ink-secondary">{{ __('visit-registration.points') }}</span>
                </dd>
            </div>
        @endif
        @if ($promotion)
            <div class="flex flex-col justify-center gap-2">
                <dt id="visit-progress-label" class="app-role-support text-app-ink-secondary">
                    {{ __($confirmed ? 'visit-registration.updated_progress' : 'visit-registration.progress') }}
                </dt>
                <dd>
                    <p id="visit-progress" class="app-role-section tabular-nums text-app-ink">
                        {{ $promotion['earned_points'] }} / {{ $promotion['target_points'] }} <span class="app-role-support text-app-ink-secondary">{{ __('visit-registration.points') }}</span>
                    </p>
                </dd>
            </div>
        @endif
    </dl>
</div>
