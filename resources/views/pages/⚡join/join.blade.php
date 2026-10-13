<div class="flex min-h-screen flex-col bg-app-canvas text-app-ink">
    <header class="mx-auto flex w-full max-w-xl justify-center px-4 pt-8 sm:px-6 sm:pt-12">
        <a href="{{ route('home') }}" aria-label="{{ __('join.home') }}" class="inline-flex min-h-app-control cursor-pointer items-center rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-app-accent-text">
            <img src="{{ asset('logo-header.webp') }}" alt="{{ __('landing.navigation.logo_alt') }}" class="h-auto w-40 sm:w-48" width="480" height="105">
        </a>
    </header>

    <main class="mx-auto flex w-full max-w-xl flex-1 flex-col gap-4 px-4 py-8 sm:px-6 sm:py-12">
        <h1 class="sr-only">
            {{ __('join.page_title') }}
        </h1>

        <div class="flex flex-col gap-4">
            <x-interactive-pass-preview
                :show-credentials="true"
                :business-name="$businessName"
                :aria-label="__('join.card_label')"
                :background-color="$backgroundColor"
                :neutral="$backgroundColor === null"
                :reward="__('join.sample_reward')"
                :reward-description="__('join.sample_description')"
                :progress="__('join.sample_progress')"
                :extra-points="__('join.sample_extra_points')"
                :deadline="__('join.sample_deadline')"
            />
            <div class="app-note-with-icon text-app-ink-help">
                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                    <flux:icon.information-circle variant="outline" class="size-4" />
                </span>
                <p class="app-role-support!">{{ __('join.sample_caption') }}</p>
            </div>
            <p class="sr-only">{{ __('join.card_notice') }}</p>
        </div>

        @if ($backgroundColor === null)
            <p class="app-role-body text-app-ink-help">
                {{ __('join.unconfigured_error') }}
            </p>
        @else
            {{-- private saving interaction stays outside the fictional example --}}
            <form
                action="{{ route('public.join.save', $publicId) }}" method="POST"
                x-data="customerPassAcquisition($el.action, $el.querySelector('[name=_token]').value)"
                x-on:submit.prevent="save(outcome => $flux.toast({
                    text: outcome === 'ready' ? @js(__('join.issued_description')) : @js(__('join.request_error')),
                    variant: outcome === 'ready' ? 'success' : 'danger',
                }))"
                x-effect="if (state === 'ready') $nextTick(() => $refs.walletLink.focus())"
                x-bind:aria-busy="state === 'loading'"
                data-customer-pass-acquisition
                class="flex flex-col gap-3"
            >
                @csrf

                <flux:button
                    type="submit" variant="primary" :loading="false"
                    x-show="state !== 'ready' && state !== 'unconfigured'" x-bind:disabled="state === 'loading' || state === 'blocked' || state === 'unconfigured'"
                    aria-label="{{ __('join.save') }}" disabled class="app-button-primary w-full"
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
                    <div class="flex flex-col items-center gap-3 rounded-2xl border border-app-border bg-app-surface p-6 text-center">
                        <p class="app-role-support text-app-ink-help">
                            {{ __('join.manual_code') }}
                        </p>
                        <strong x-text="manualCode" class="select-all font-mono text-5xl tracking-widest"></strong>
                        <p class="app-role-support text-app-ink-help">
                            {{ __('join.manual_code_help') }}
                        </p>
                    </div>

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
