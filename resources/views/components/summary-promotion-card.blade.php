@props([
    'promotion',
    'period',
    'phase',
    'context' => 'primary',
])

@php
    $upcoming = $context === 'upcoming';
    $headingId = $upcoming ? 'next-promotion-title' : 'promotion-title';
    $descriptionId = $upcoming ? 'next-promotion-description' : 'promotion-description';
@endphp

<div class="flex flex-wrap items-center gap-3">
    <flux:heading level="2" :id="$headingId" tabindex="-1" class="app-role-section! text-app-ink!">
        {{ __('summary.'.($upcoming ? 'next' : 'promotion')) }}
    </flux:heading>
    @if ($promotion)
        <flux:badge :color="$phase === 'active' ? 'green' : 'blue'" class="app-role-support! font-medium!">
            {{ __('business.pass.promotion_'.$phase.'_status') }}
        </flux:badge>
    @endif
</div>
<div class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-4">
    <span aria-hidden="true" @class([
        'flex size-12 shrink-0 items-center justify-center rounded-xl border',
        'border-app-priority-border bg-app-scheduled-surface text-app-ink-priority' => $promotion !== null,
        'border-app-border text-app-ink-secondary' => $promotion === null,
    ])>
        <flux:icon :name="$upcoming ? 'calendar-days' : ($promotion ? 'gift' : 'megaphone')" variant="outline" class="size-6" />
    </span>
    <div class="flex min-w-0 flex-col gap-1">
        @if ($promotion)
            <flux:heading level="3" class="app-role-card! text-app-ink!">
                {{ $promotion->reward_title }}
            </flux:heading>
        @else
            <p class="app-role-body font-medium text-app-ink">
                {{ __('summary.waiting') }}
            </p>
        @endif
        @if ($promotion?->reward_description)
            <p id="{{ $descriptionId }}" class="app-role-support! break-words text-app-ink-secondary!">
                {{ $promotion->reward_description }}
            </p>
        @elseif (! $promotion)
            <div class="app-note-with-icon text-app-ink-help">
                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                    <flux:icon.information-circle variant="outline" class="size-4" />
                </span>
                <p id="{{ $descriptionId }}" class="app-role-support">
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
                {{ $promotion ? __('summary.points', ['count' => $promotion->target_points]) : '—' }}
            </dd>
        </div>
        <div class="min-w-0 space-y-1">
            <dt class="app-role-support! flex items-center gap-2 font-medium! text-app-ink-secondary">
                <flux:icon.calendar-days variant="outline" aria-hidden="true" class="size-5 shrink-0 text-app-accent" />
                {{ __('business.promotion.review_validity') }}
            </dt>
            <dd class="app-role-body! break-words font-semibold! text-app-ink">
                @if ($period)
                    <x-regional-date :date="$period['start']" :end-date="$period['end']" />
                @else
                    —
                @endif
            </dd>
        </div>
    </dl>
    @if ($promotion)
        <div class="col-start-2">
            <flux:button
                id="summary-{{ $context }}-detail-trigger-{{ $promotion->public_id }}" type="button"
                wire:click="showPromotionDetail('{{ $promotion->public_id }}', '{{ $context }}')"
                wire:loading.attr="disabled" wire:target="showPromotionDetail"
                :variant="$phase === 'active' ? 'outline' : 'filled'"
                @class([
                    'w-full sm:w-auto',
                    'app-button-secondary-on-emphasis' => $phase === 'active',
                    'app-button-secondary' => $phase !== 'active',
                ])
            >
                {{ __('business.pass.view_promotion_detail') }}
            </flux:button>
        </div>
    @endif
</div>
