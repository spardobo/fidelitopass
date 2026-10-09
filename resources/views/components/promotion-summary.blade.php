@props([
    'rewardTitle',
    'rewardDescription' => '',
    'targetPoints',
    'startDate',
    'endDate',
    'extraPoints' => [],
])

<div {{ $attributes->class('min-w-0 space-y-3 rounded-2xl border border-app-border bg-app-surface p-4 sm:p-6') }}>
    <div class="min-w-0 space-y-1">
        <flux:heading level="3" class="app-role-card! break-words text-app-ink!">
            {{ $rewardTitle }}
        </flux:heading>
        @if ($rewardDescription !== '')
            <flux:text class="app-role-support! break-words text-app-ink-secondary!">
                {{ $rewardDescription }}
            </flux:text>
        @endif
    </div>

    <dl class="border-t border-app-line">
        <div class="grid min-w-0 grid-cols-[1.25rem_minmax(0,1fr)] gap-x-2 gap-y-1 py-3 sm:grid-cols-[1.5rem_8rem_minmax(0,1fr)] sm:gap-x-3 sm:gap-y-0">
            <span class="row-span-2 flex items-center justify-center sm:row-span-1" aria-hidden="true">
                <flux:icon.trophy variant="outline" class="size-5 shrink-0 text-app-accent" />
            </span>
            <dt class="app-role-support! col-start-2 min-w-0 break-words font-medium! text-app-ink-secondary sm:col-start-2 sm:row-start-1 sm:self-center">
                {{ __('business.promotion.review_goal') }}
            </dt>
            <dd class="app-role-body! col-start-2 min-w-0 break-words font-semibold text-app-ink sm:col-start-3 sm:row-start-1">
                {{ $targetPoints }} {{ __('business.promotion.points_unit') }}
            </dd>
        </div>
        <div class="grid min-w-0 grid-cols-[1.25rem_minmax(0,1fr)] gap-x-2 gap-y-1 border-t border-app-line py-3 sm:grid-cols-[1.5rem_8rem_minmax(0,1fr)] sm:gap-x-3 sm:gap-y-0">
            <span class="row-span-2 flex items-center justify-center sm:row-span-1" aria-hidden="true">
                <flux:icon.calendar-days variant="outline" class="size-5 shrink-0 text-app-accent" />
            </span>
            <dt class="app-role-support! col-start-2 min-w-0 break-words font-medium! text-app-ink-secondary sm:col-start-2 sm:row-start-1 sm:self-center">
                {{ __('business.promotion.review_validity') }}
            </dt>
            <dd class="app-role-body! col-start-2 min-w-0 break-words font-semibold text-app-ink sm:col-start-3 sm:row-start-1">
                {{ $startDate }} – {{ $endDate }}
                <p class="app-role-support! mt-1 font-normal! text-app-ink-help">
                    {{ __('business.promotion.inclusive_end_date') }}
                </p>
            </dd>
        </div>
    </dl>

    @if ($extraPoints === [])
        <p class="app-role-support! border-t border-app-line pt-3 text-app-ink-secondary">
            {{ __('business.promotion.review_no_extra_points') }}
        </p>
    @else
        <details data-test="promotion-review-extra-rules" data-promotion-review-extra-rules class="min-w-0 border-t border-app-line">
            <summary class="app-focus grid min-h-11 cursor-pointer list-none grid-cols-[1.25rem_minmax(0,1fr)] items-start gap-x-2 gap-y-1 rounded-md py-3 sm:grid-cols-[1.5rem_8rem_minmax(0,1fr)] sm:gap-x-3 sm:gap-y-0">
                <span data-test="promotion-review-extra-icon" class="row-span-2 flex self-stretch items-center justify-center sm:row-span-1" aria-hidden="true">
                    <flux:icon.sparkles variant="outline" class="size-5 shrink-0 text-app-accent" />
                </span>
                <span class="app-role-support! col-start-2 min-w-0 break-words font-medium! text-app-ink-secondary sm:col-start-2 sm:row-start-1">
                    {{ __('business.promotion.review_extra_points') }}
                </span>
                <span class="app-role-support! col-start-2 flex min-w-0 items-start justify-between gap-2 font-semibold! text-app-ink sm:col-start-3 sm:row-start-1">
                    {{ trans_choice('business.promotion.configuration_count', count($extraPoints)) }}
                    <flux:icon.chevron-down data-extra-rules-chevron variant="outline" class="size-4 shrink-0 self-center text-app-accent" aria-hidden="true" />
                </span>
            </summary>
            <ul aria-label="{{ __('business.promotion.review_extra_points') }}" class="space-y-2 pb-3">
                @foreach ($extraPoints as $rule)
                    <li wire:key="review-rule-{{ $rule['weekday'] }}-{{ $rule['start_time'] ?? 'all-day' }}" class="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-lg border border-app-border bg-app-control px-3 py-2">
                        <span class="app-role-support! min-w-0 break-words text-app-ink-secondary">
                            {{ __('business.promotion.weekdays.'.$rule['weekday']) }} · {{ $rule['start_time'] === null ? __('business.promotion.all_day') : $rule['start_time'].'–'.$rule['end_time'] }}
                        </span>
                        <flux:badge color="violet" class="app-role-support! shrink-0 font-medium!">
                            ×{{ $rule['multiplier'] }}
                        </flux:badge>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>

@once
    <style>
        [data-promotion-review-extra-rules][open] [data-extra-rules-chevron] {
            transform: rotate(180deg);
        }

        [data-extra-rules-chevron] {
            transition: transform 180ms ease;
        }

        @media (prefers-reduced-motion: reduce) {
            [data-extra-rules-chevron] {
                transition: none;
            }
        }
    </style>
@endonce
