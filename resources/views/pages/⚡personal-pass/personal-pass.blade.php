<div class="app-personal-pass bg-app-canvas text-app-ink">
    @php
        $state = $presentation['state'] ?? $error;
        $icon = match ($state) {
            'scheduled', 'ended', 'expired' => 'clock',
            'zero', 'earning' => 'tag',
            'available' => 'gift',
            'redeemed' => 'check-circle',
            'cancelled' => 'x-circle',
            'invalid', 'unavailable' => 'exclamation-triangle',
            default => 'information-circle',
        };
    @endphp
    <main
        data-personal-state="{{ $state }}"
        class="mx-auto flex min-h-screen w-full max-w-xl flex-col gap-8 px-6 py-8 text-center sm:py-12"
    >
        <header class="flex flex-col items-center gap-4">
            <span aria-hidden="true" class="app-personal-icon flex size-16 shrink-0 items-center justify-center rounded-full">
                <flux:icon :name="$icon" variant="outline" class="size-9" />
            </span>
            @if ($businessName !== null)
                <flux:heading level="1" class="app-role-title! max-w-full break-words text-app-ink!">
                    {{ $businessName }}
                </flux:heading>
            @endif
        </header>

        <section aria-labelledby="personal-status" class="flex min-w-0 flex-col gap-4">
            <flux:heading id="personal-status" :level="$error === null ? 2 : 1" class="app-role-title! max-w-full break-words">
                {{ __('personal-pass.states.'.$state) }}
            </flux:heading>

            @if ($error !== null)
                <flux:error :icon="false" :message="__('personal-pass.'.$error)" class="app-role-body! mt-0! text-app-accent!" />
            @else
                @if ($presentation['reward'] !== null)
                    <p class="app-role-intro break-words text-app-ink">
                        {{ $presentation['reward'] }}
                    </p>
                @endif
                @if ($presentation['description'] !== null && $presentation['description'] !== '')
                    <p class="app-role-body break-words text-app-ink-secondary">
                        {{ $presentation['description'] }}
                    </p>
                @endif

                @if ($state === 'waiting')
                    <p class="app-role-body text-app-ink-secondary">
                        {{ __('personal-pass.waiting') }}
                    </p>
                @elseif (in_array($state, ['zero', 'earning'], true))
                    <p data-personal-progress class="flex min-w-0 flex-col items-center gap-4 rounded-[20px] border border-app-border bg-app-surface p-4 sm:p-6">
                        <span class="app-role-personal-count flex min-w-0 flex-wrap items-baseline justify-center gap-x-3">
                            <span class="max-w-full break-words text-app-accent">
                                {{ $presentation['points'] }}
                            </span>
                            <span class="min-w-0 max-w-full break-words text-app-ink">
                                {{ __('personal-pass.progress_target', ['target' => $presentation['target']]) }}
                            </span>
                        </span>
                        <span class="app-role-body text-app-ink-secondary">
                            {{ __('personal-pass.points') }}
                        </span>
                    </p>
                @endif

                {{-- temporal facts --}}
                @if (in_array($state, ['zero', 'earning'], true))
                    <p class="app-role-support font-bold text-app-ink-secondary">
                        {{ __('personal-pass.deadline', ['date' => $presentation['date']]) }}
                    </p>
                @elseif ($state === 'scheduled')
                    <p class="app-role-support font-bold text-app-ink-secondary">
                        {{ __('personal-pass.starts', ['date' => $presentation['date']]) }}
                    </p>
                @elseif ($state === 'available')
                    <p class="app-role-support font-bold text-app-ink-secondary">
                        {{ __('personal-pass.redeem_deadline', ['date' => $presentation['date']]) }}
                    </p>
                @elseif (in_array($state, ['ended', 'expired'], true))
                    <p class="app-role-support font-bold text-app-ink-secondary">
                        {{ __('personal-pass.'.$state, ['date' => $presentation['date']]) }}
                    </p>
                @endif

                @if ($presentation['redeemed_date'] !== null)
                    <p class="app-role-support font-bold text-app-ink-secondary">
                        {{ __('personal-pass.'.($state === 'cancelled' ? 'cancelled_redeemed' : 'redeemed'), ['date' => $presentation['redeemed_date']]) }}
                    </p>
                @endif

                @if (in_array($state, ['zero', 'earning'], true) && $presentation['visit_points'] > 1)
                    <p class="app-personal-multiplier app-role-body flex min-w-0 max-w-full items-center gap-2 self-center rounded-[20px] border border-app-priority-border bg-app-emphasis p-4 text-app-ink-priority sm:p-6">
                        <flux:icon.star variant="solid" aria-hidden="true" class="size-5 shrink-0" />
                        <span class="min-w-0 break-words">
                            {{ __('personal-pass.multiplier', ['points' => $presentation['visit_points']]) }}
                        </span>
                    </p>
                @endif

                {{-- next steps --}}
                @if (in_array($state, ['zero', 'earning'], true))
                    <p class="app-role-body text-app-accent">
                        {{ __('personal-pass.visit_'.$state) }}
                    </p>
                @elseif ($state === 'scheduled')
                    <p class="app-role-body text-app-accent">
                        {{ __('personal-pass.scheduled') }}
                    </p>
                @elseif ($state === 'available')
                    <p class="app-role-body text-app-accent">
                        {{ __('personal-pass.redeem') }}
                    </p>
                @endif
            @endif
        </section>
    </main>
</div>
