@props(['detail', 'closeLabel', 'focusFallback'])

<flux:modal
    {{ $attributes }}
    name="promotion-detail"
    scroll="body"
    x-on:close="
        const originId = 'promotion-detail-trigger-' + $wire.selectedPromotionId;
        $wire.dismissPromotionDetail().then(() => {
            const root = $el.closest('main');
            const origin = root.querySelector('#' + originId);
            const canFocusOrigin = origin && !origin.closest('details:not([open])') && origin.getClientRects().length > 0;
            const focusTarget = canFocusOrigin ? origin : root.querySelector('#{{ $focusFallback }}');
            focusTarget?.focus({ preventScroll: true });
        });
    "
    class="app-theme w-[calc(100vw-2rem)] sm:w-[calc(100vw-3rem)] max-w-xl! min-w-0! max-h-[calc(100dvh-2rem)]! sm:max-h-[calc(100dvh-3rem)]! flex flex-col overflow-hidden!"
>
    @if ($detail)
        <div data-promotion-detail-phase="{{ $detail['phase'] }}" class="flex min-h-0 flex-1 flex-col">
            <header class="flex shrink-0 min-w-0 items-center gap-4 pb-2 pe-10">
                <span class="flex size-16 shrink-0 items-center justify-center rounded-full bg-app-emphasis text-app-accent" aria-hidden="true">
                    <flux:icon.document-text variant="outline" class="size-9" />
                </span>
                <div class="min-w-0 space-y-1">
                    <flux:badge :color="match ($detail['phase']) { 'active' => 'green', 'scheduled' => 'blue', 'cancelled' => 'red', default => null }" class="app-role-support! font-medium!">
                        {{ __('business.pass.promotion_'.$detail['phase'].'_status') }}
                    </flux:badge>
                    <flux:heading level="2" id="promotion-detail-heading" tabindex="-1" class="app-role-title! break-words text-app-ink!">
                        {{ __('business.pass.promotion_detail_title') }}
                    </flux:heading>
                    <flux:text class="app-role-support! break-words text-app-ink-secondary!">
                        {{ __('business.pass.promotion_detail_description') }}
                    </flux:text>
                </div>
            </header>

            <div data-test="promotion-detail-scroll-body" tabindex="0" autofocus class="min-h-0 flex-1 overflow-y-auto py-3">
                <x-promotion-summary
                    :reward-title="$detail['promotion']->reward_title"
                    :reward-description="$detail['promotion']->reward_description"
                    :target-points="$detail['promotion']->target_points"
                    :start-date="$detail['start_date']"
                    :end-date="$detail['end_date']"
                    :extra-points="$detail['extra_points']"
                />

                {{ $feedback ?? '' }}
            </div>

            <footer class="flex shrink-0 flex-col-reverse justify-end gap-3 pt-3 sm:flex-row">
                <flux:modal.close class="w-full sm:w-auto">
                    <flux:button type="button" class="app-button-secondary min-h-11 w-full sm:w-auto">
                        {{ $closeLabel }}
                    </flux:button>
                </flux:modal.close>
                {{ $actions ?? '' }}
            </footer>
        </div>
    @endif
</flux:modal>
