<main class="app-theme app-workspace wrap-anywhere">
    <header class="space-y-2">
        <p class="app-role-body! text-app-accent">
            {{ $business->name }} · {{ __('business.pass.business_label') }}
        </p>
        <flux:heading level="1" class="app-heading">
            {{ __('invitation.title') }}
        </flux:heading>
        <flux:text class="app-role-intro! app-text-secondary max-w-3xl">
            {{ __('invitation.description') }}
        </flux:text>
    </header>

    <div class="grid min-w-0 items-start gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        <section aria-labelledby="invitation-qr-heading" class="flex min-w-0 flex-col gap-4 rounded-[20px] border border-app-priority-border bg-app-emphasis p-4 sm:p-6 lg:sticky lg:top-8 lg:self-start">
            <div class="space-y-2">
                <flux:heading level="2" id="invitation-qr-heading" class="app-role-section! text-app-ink!">
                    {{ __('invitation.qr_heading') }}
                </flux:heading>
                <flux:text class="app-role-body! app-text-secondary">
                    {{ __($appearanceSaved ? 'invitation.appearance_saved' : 'invitation.appearance_required') }}
                </flux:text>
            </div>

            <div
                role="group"
                aria-label="{{ __('invitation.qr_preview_label') }}"
                @class([
                    'mx-auto flex aspect-square w-full max-w-56 flex-col items-center justify-center gap-4 rounded-[20px]',
                    'border border-dashed border-app-priority-border bg-app-control p-4 text-center' => $qrSvg === null,
                ])
            >
                @if ($qrSvg !== null)
                    <img src="data:image/svg+xml;base64,{{ base64_encode($qrSvg) }}" alt="{{ __('invitation.qr_alt') }}" class="block size-full" />
                @elseif ($appearanceSaved)
                    <flux:icon.exclamation-triangle variant="outline" class="size-8 text-app-ink-priority" />
                    <p role="status" class="app-role-support! text-app-ink-help">
                        {{ __('invitation.generation_failed') }}
                    </p>
                    <flux:button wire:click="retryQr" variant="outline" class="app-button-secondary-on-emphasis">
                        {{ __('invitation.retry') }}
                    </flux:button>
                @else
                    <flux:icon.lock-closed variant="outline" class="size-8 text-app-ink-priority" />
                    <p class="app-role-action!">
                        {{ __('invitation.preparation_pending') }}
                    </p>
                @endif
            </div>

            @if ($qrUrl !== null)
                <div x-data="invitationLink" class="space-y-2">
                    <flux:field>
                        <flux:label for="invitation-url">{{ __('invitation.url_label') }}</flux:label>

                        <div class="relative">
                            <flux:input
                                id="invitation-url"
                                :value="$qrUrl"
                                x-ref="url"
                                readonly
                                class:input="app-input pe-app-control!"
                            />
                            <flux:button
                                type="button"
                                variant="subtle"
                                square
                                x-on:click="copy"
                                x-bind:disabled="copying"
                                aria-label="{{ __('Copy to clipboard') }}"
                                class="app-button absolute! inset-y-0 end-0 min-w-app-control border-0! bg-transparent! text-app-ink-priority! hover:bg-transparent! active:bg-transparent! active:opacity-60!"
                            >
                                <flux:icon.clipboard-document variant="outline" class="size-4" />
                            </flux:button>
                        </div>
                    </flux:field>

                    <flux:text x-cloak x-show="copyFailed" role="status" class="app-role-support! text-app-ink-help">
                        {{ __('invitation.copy_failed') }}
                    </flux:text>
                </div>
            @endif

            @if ($appearanceSaved)
                <flux:button
                    type="button"
                    variant="outline"
                    icon="arrow-down-tray"
                    icon:variant="outline"
                    wire:click="downloadQr"
                    wire:loading.attr="disabled"
                    wire:target="downloadQr"
                    aria-describedby="invitation-action-help"
                    :disabled="$qrSvg === null"
                    class="app-button-secondary-on-emphasis w-full"
                >
                    {{ __('invitation.download') }}
                </flux:button>
            @else
                <flux:button
                    :href="route('business.pass.appearance')"
                    variant="outline"
                    wire:navigate
                    aria-describedby="invitation-action-help"
                    class="app-button-secondary-on-emphasis w-full"
                >
                    {{ __('invitation.prepare_pass') }}
                </flux:button>
            @endif

            <div id="invitation-action-help" class="app-note-with-icon text-app-ink-help">
                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                    <flux:icon.information-circle variant="outline" class="size-4" />
                </span>
                <p class="app-role-support!">
                    {{ __($appearanceSaved ? 'invitation.download_help_saved' : 'invitation.download_help') }}
                </p>
            </div>
        </section>

        <section aria-labelledby="invitation-instructions-heading" class="min-w-0 space-y-6 rounded-[20px] border border-app-border bg-app-surface p-4 sm:p-6">
            <div class="space-y-2">
                <flux:heading level="2" id="invitation-instructions-heading" class="app-role-section! text-app-ink!">
                    {{ __('invitation.instructions_heading') }}
                </flux:heading>
                <p class="app-role-body! app-text-secondary">
                    {{ __('invitation.instructions_description') }}
                </p>
            </div>

            <ol role="list" class="space-y-6">
                @foreach (['printer', 'wallet', 'check-circle'] as $index => $icon)
                    <li wire:key="invitation-step-{{ $index }}" class="flex items-start gap-4">
                        <span aria-hidden="true" class="app-role-action! flex size-8 shrink-0 items-center justify-center rounded-full bg-app-emphasis text-app-accent-text">
                            {{ $index + 1 }}
                        </span>
                        <div class="min-w-0 space-y-2">
                            <flux:heading level="3" class="app-role-card! flex items-start gap-2 text-app-ink!">
                                @if ($index === 1)
                                    <img src="{{ asset('images/google-wallet/wallet-icon.png') }}" alt="" width="20" height="20" class="mt-1 size-5 shrink-0 object-contain">
                                @else
                                    <flux:icon :name="$icon" variant="outline" class="mt-1 size-5 text-app-accent-text" />
                                @endif
                                <span>
                                    {{ __('invitation.step_'.($index + 1).'_heading') }}
                                </span>
                            </flux:heading>
                            <p class="app-role-body! app-text-secondary">
                                {{ __('invitation.step_'.($index + 1).'_description') }}
                            </p>
                        </div>
                    </li>
                @endforeach
            </ol>

            <div class="app-note-with-icon text-app-ink-help">
                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                    <flux:icon.information-circle variant="outline" class="size-4" />
                </span>
                <p class="app-role-support!">
                    {{ __('invitation.public_private_note') }}
                </p>
            </div>

            <figure class="space-y-3 border-t border-app-line pt-6">
                <figcaption class="space-y-2">
                    <p class="app-role-card text-app-ink">
                        {{ __('invitation.print_example_heading') }}
                    </p>
                    <p class="app-role-support text-app-ink-secondary">
                        {{ __('invitation.print_example_description') }}
                    </p>
                </figcaption>
                <img src="{{ asset('table_tend_card.png') }}" alt="{{ __('invitation.print_example_alt') }}" width="2304" height="1536" loading="lazy" decoding="async" class="block h-auto w-full rounded-2xl border border-app-border" />
            </figure>
        </section>
    </div>
</main>
