<div
    @if ($backgroundColor !== null)
        x-data="customerPassAcquisition($el.querySelector('form').action, $el.querySelector('[name=_token]').value)"
    @endif
    class="flex min-h-screen flex-col bg-app-canvas text-app-ink"
>
    <main class="mx-auto flex w-full max-w-xl flex-1 flex-col gap-4 px-6 py-8 sm:py-12">
        <div
            @if ($backgroundColor !== null)
                x-show="state !== 'ready'"
            @endif
            class="flex flex-col items-center gap-4 text-center"
        >
            <span aria-hidden="true" class="flex size-16 shrink-0 items-center justify-center rounded-full bg-app-emphasis text-app-accent">
                <flux:icon.arrow-down-tray variant="outline" class="size-9" />
            </span>
            <div class="min-w-0 space-y-1">
                <flux:heading level="1" class="app-role-title! break-words text-app-ink!">
                    {{ $businessName }}
                </flux:heading>
                <p class="app-role-body text-app-ink-secondary">
                    {{ __('join.subtitle') }}
                </p>
            </div>
        </div>

        @if ($backgroundColor === null)
            <p class="app-role-body text-app-ink-help">
                {{ __('join.unconfigured_error') }}
            </p>
        @else
            <div x-show="state === 'ready'" x-cloak class="flex flex-col items-center gap-4 text-center">
                <span aria-hidden="true" class="flex size-16 shrink-0 items-center justify-center rounded-full bg-app-success-surface text-app-success-ink">
                    <flux:icon.check variant="outline" class="size-9" />
                </span>
                <div class="min-w-0 space-y-1">
                    <flux:heading
                        id="join-ready-heading" level="1"
                        x-ref="readyHeading"
                        tabindex="-1"
                        class="app-role-title! break-words text-app-ink! focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-app-accent-text"
                    >
                        {{ __('join.ready_heading', ['business' => $businessName]) }}
                    </flux:heading>
                    <p class="app-role-body text-app-ink-secondary">
                        {{ __('join.ready_subtitle') }}
                    </p>
                </div>
            </div>

            <form
                action="{{ route('public.join.save', $publicId) }}" method="POST"
                x-on:submit.prevent="save(outcome => {
                    $flux.toast({
                        text: outcome === 'ready' ? @js(__('join.issued_description')) : @js(__('join.request_error')),
                        variant: outcome === 'ready' ? 'success' : 'danger',
                    });
                    if (outcome === 'ready') $nextTick(() => requestAnimationFrame(() => {
                        window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
                        $refs.readyHeading.focus({ preventScroll: true });
                    }));
                })"
                x-bind:aria-busy="state === 'loading'"
                data-customer-pass-acquisition
                class="flex flex-col gap-4"
            >
                @csrf

                <section
                    x-show="state !== 'ready' && state !== 'unconfigured'"
                    aria-labelledby="join-initial-heading"
                    class="min-w-0 space-y-6 rounded-[20px] border border-app-priority-border bg-app-emphasis p-4 text-app-ink-priority sm:p-6"
                >
                    <flux:heading id="join-initial-heading" level="2" class="app-role-section! text-app-ink-priority!">
                        {{ __('join.initial_heading') }}
                    </flux:heading>
                    <ol role="list" class="space-y-6">
                        @foreach (__('join.initial_steps') as $index => $step)
                            <li class="flex items-start gap-4">
                                <span aria-hidden="true" class="app-role-action! flex size-8 shrink-0 items-center justify-center rounded-full bg-app-accent text-app-on-accent">
                                    {{ $index + 1 }}
                                </span>
                                <div class="min-w-0 space-y-2">
                                    <flux:heading level="3" class="app-role-card! flex items-start gap-2 text-app-ink-priority!">
                                        @if ($index === 1)
                                            <img src="{{ asset('images/google-wallet/wallet-icon.png') }}" alt="" width="20" height="20" class="mt-1 size-5 shrink-0 object-contain">
                                        @else
                                            <flux:icon :name="$index === 0 ? 'arrow-down-tray' : 'check-circle'" variant="outline" class="mt-1 size-5 text-app-accent-text" />
                                        @endif
                                        <span>
                                            {{ $step['title'] }}
                                        </span>
                                    </flux:heading>
                                    <p class="app-role-body! text-app-ink-priority">
                                        {{ $step['description'] }}
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </section>

                <flux:button
                    type="submit" variant="primary" :loading="false"
                    x-show="state !== 'ready' && state !== 'unconfigured'" x-bind:disabled="state === 'loading' || state === 'blocked' || state === 'unconfigured'"
                    aria-label="{{ __('join.save') }}" disabled class="app-button-primary mt-2 w-full"
                >
                    <span x-show="state !== 'loading'">
                        {{ __('join.save') }}
                    </span>
                    <span x-show="state === 'loading'" x-cloak>
                        <flux:icon.loading variant="mini" class="size-5" />
                    </span>
                </flux:button>

                <div x-show="['retryable', 'blocked', 'unconfigured'].includes(state)" x-cloak class="app-role-body text-app-ink-help">
                    <flux:error
                        :message="__('join.unconfigured_error')"
                        x-show="state === 'unconfigured'" x-cloak
                        class="app-error mt-0!"
                    />
                    <flux:error
                        :message="__('join.retryable_error')"
                        x-show="state === 'retryable'" x-cloak
                        class="app-error mt-0!"
                    />
                    <flux:error
                        :message="__('join.unknown_error')"
                        x-show="state === 'blocked'" x-cloak
                        class="app-error mt-0!"
                    />
                </div>

                <div x-show="state === 'ready'" x-cloak class="flex flex-col gap-4">
                    <div class="flex flex-col gap-2">
                        <div class="flex justify-center p-2">
                            <a
                                target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer"
                                x-ref="walletLink" x-bind:href="saveUrl || null"
                                aria-label="{{ __('join.open_wallet') }}"
                                class="inline-flex rounded-full focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-app-accent-text"
                            >
                                <img src="{{ asset('images/google-wallet/add-to-google-wallet-es-419.svg') }}" alt="{{ __('join.add_to_wallet') }}" width="239" height="55" class="h-14 w-auto">
                            </a>
                        </div>
                        <div class="app-note-with-icon text-app-ink-help">
                            <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                                <flux:icon.information-circle variant="outline" class="size-4" />
                            </span>
                            <p class="app-role-support">
                                {{ __('join.wallet_note') }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-col items-center gap-4 rounded-[20px] border border-app-border bg-app-surface p-4 text-center sm:p-6">
                        <flux:heading level="2" class="app-role-section! text-app-ink!">
                            {{ __('join.manual_code') }}
                        </flux:heading>
                        <strong x-text="manualCode" class="select-all font-mono text-5xl tracking-widest"></strong>
                        <p class="app-role-support text-app-ink-help">
                            {{ __('join.manual_code_help') }}
                        </p>
                    </div>

                    <section aria-labelledby="join-wallet-instructions-heading" class="min-w-0 space-y-6 rounded-[20px] border border-app-priority-border bg-app-emphasis p-4 text-app-ink-priority sm:p-6">
                        <flux:heading id="join-wallet-instructions-heading" level="2" class="app-role-section! text-app-ink-priority!">
                            {{ __('join.ready_instructions_heading') }}
                        </flux:heading>
                        <ol role="list" class="space-y-6">
                            @foreach (__('join.ready_steps') as $index => $step)
                                <li class="flex items-start gap-4">
                                    <span aria-hidden="true" class="app-role-action! flex size-8 shrink-0 items-center justify-center rounded-full bg-app-accent text-app-on-accent">
                                        {{ $index + 1 }}
                                    </span>
                                    <div class="min-w-0 space-y-2">
                                        <flux:heading level="3" class="app-role-card! flex items-start gap-2 text-app-ink-priority!">
                                            @if ($index === 0)
                                                <img src="{{ asset('images/google-wallet/wallet-icon.png') }}" alt="" width="20" height="20" class="mt-1 size-5 shrink-0 object-contain">
                                            @else
                                                <flux:icon :name="$index === 1 ? 'user-circle' : 'check-circle'" variant="outline" class="mt-1 size-5 text-app-accent-text" />
                                            @endif
                                            <span>
                                                {{ $step['title'] }}
                                            </span>
                                        </flux:heading>
                                        <p class="app-role-body! text-app-ink-priority">
                                            {{ $step['description'] }}
                                        </p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                </div>

                <noscript>
                    <p class="app-role-body text-app-ink-help">
                        {{ __('join.javascript_required') }}
                    </p>
                </noscript>
            </form>
        @endif
    </main>
</div>
