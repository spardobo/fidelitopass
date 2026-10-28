<div class="bg-app-canvas text-app-ink">
    @php
        $state = $presentation['state'] ?? $error;
        $icon = match ($state) {
            'available' => 'gift',
            'redeemed' => 'check',
            'scheduled' => 'clock',
            'cancelled', 'expired', 'ended' => 'x-mark',
            'invalid', 'unavailable' => 'exclamation-triangle',
            default => 'information-circle',
        };
        $iconSurface = $state === 'redeemed'
            ? 'bg-app-success-surface text-app-success-ink'
            : 'bg-app-emphasis text-app-accent';
    @endphp
    <main class="mx-auto flex min-h-screen w-full max-w-xl flex-col gap-8 px-6 py-8 text-center sm:py-12">
        <header class="flex flex-col items-center gap-4">
            <span aria-hidden="true" class="flex size-16 shrink-0 items-center justify-center rounded-full {{ $iconSurface }}">
                <flux:icon :name="$icon" variant="outline" class="size-9" />
            </span>
            @if ($businessName !== null)
                <flux:heading level="1" class="app-role-title! break-words text-app-ink!">
                    {{ $businessName }}
                </flux:heading>
            @endif
        </header>

        @if ($error !== null)
            <flux:error :message="__('personal-pass.'.$error)" class="app-error app-role-body! mt-0!" />
        @else
            <section aria-labelledby="personal-status" class="flex min-w-0 flex-col gap-4">
                <flux:heading id="personal-status" level="2" class="app-role-section! break-words text-app-ink!">
                    {{ __('personal-pass.states.'.$state, ['date' => $presentation['date'], 'reward' => $presentation['reward']]) }}
                </flux:heading>

                @if ($presentation['reward'] !== null && $state !== 'zero')
                    <p class="app-role-body break-words text-app-ink">{{ $presentation['reward'] }}</p>
                @endif
                @if ($presentation['description'] !== null && $presentation['description'] !== '')
                    <p class="app-role-body break-words text-app-ink-secondary">{{ $presentation['description'] }}</p>
                @endif

                @if (in_array($state, ['zero', 'earning'], true))
                    <p class="app-role-metric text-app-ink">{{ __('personal-pass.progress', ['points' => $presentation['points'], 'target' => $presentation['target']]) }}</p>
                    <p class="app-role-support text-app-ink-secondary">{{ __('personal-pass.deadline', ['date' => $presentation['date']]) }}</p>
                    @if ($presentation['visit_points'] > 1)
                        <p class="app-role-body text-app-accent-text">{{ __('personal-pass.multiplier', ['points' => $presentation['visit_points']]) }}</p>
                    @endif
                    <p class="app-role-body text-app-ink">{{ __('personal-pass.visit') }}</p>
                @elseif ($state === 'scheduled')
                    <p class="app-role-body text-app-ink-secondary">{{ __('personal-pass.target', ['target' => $presentation['target']]) }}</p>
                    <p class="app-role-body text-app-ink">{{ __('personal-pass.scheduled', ['reward' => $presentation['reward']]) }}</p>
                @elseif ($state === 'available')
                    <p class="app-role-body text-app-ink">{{ __('personal-pass.redeem', ['date' => $presentation['date']]) }}</p>
                @elseif (in_array($state, ['ended', 'expired', 'cancelled'], true))
                    <p class="app-role-body text-app-ink-secondary">{{ __('personal-pass.'.$state, ['date' => $presentation['date']]) }}</p>
                @endif

                @if ($presentation['redeemed_date'] !== null)
                    <p class="app-role-body text-app-ink-secondary">{{ __('personal-pass.redeemed', ['date' => $presentation['redeemed_date']]) }}</p>
                @endif
            </section>
        @endif
    </main>
</div>
