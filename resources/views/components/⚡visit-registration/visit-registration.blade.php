<fieldset wire:loading.attr="disabled" wire:target="findPass,acceptVisit,findVisitReceipt,resetIdentification" x-data="visitRegistration(@js($clientFailures))" x-on:visit-camera-enable="requestCameraStart()" x-on:visit-camera-disable="reset(true)" x-on:qr-camera-fatal-error="cameraFailed($event.detail)" x-on:livewire-transport-error="logTransportError($event.detail)" x-bind:disabled="busy" x-bind:aria-busy="busy" x-bind:data-focus-modality="focusModality" class="app-theme contents [&[data-focus-modality=pointer]_button:focus]:outline-none!">
    <flux:modal name="visit-registration" class="app-theme flex max-h-[calc(100dvh-2rem)]! w-[calc(100vw-2rem)] min-w-0! max-w-lg! flex-col overflow-hidden! rounded-[1.25rem]! border border-app-border bg-app-surface! p-4! sm:p-6!">
        <header class="shrink-0 space-y-2 border-b border-app-line pb-4 pe-8">
            <flux:heading level="2" class="app-role-title! text-app-ink!">
                {{ __('visit-registration.title') }}
            </flux:heading>
            <p class="app-role-support text-app-ink-secondary">
                {{ __('visit-registration.introduction') }}
            </p>
        </header>

        <div wire:loading.attr="aria-busy" wire:target="findPass,acceptVisit,findVisitReceipt,resetIdentification" aria-busy="false" class="-mx-2 min-h-0 flex-1 overflow-y-auto px-2 py-4">
            <!-- preparation feedback -->
            <div x-bind:hidden="presentationReady || !busy" x-cloak class="app-note-with-icon app-role-support text-app-ink-secondary">
                <flux:icon.loading class="size-5 shrink-0" />
                {{ __('visit-registration.resetting') }}
            </div>
            <div x-bind:hidden="presentationReady || busy || !clientErrorCode" x-cloak class="space-y-4">
                <flux:error :message="__('visit-registration.read_unavailable')" :icon="false" x-text="errorMessage" class="app-error" />
                <div class="flex justify-end">
                    <flux:button type="button" variant="primary" :loading="true" wire:target="resetIdentification" x-on:click="reset()" x-bind:disabled="busy" data-visit-restart class="app-button-primary">
                        {{ __('visit-registration.reset_retry') }}
                    </flux:button>
                </div>
            </div>
            <div x-bind:hidden="!presentationReady">
                <!-- identification, confirmation and receipt -->
                @if ($stage === 'identify')
                    <section wire:key="visit-identify" class="space-y-4">
                        <x-modal-step-heading id="visit-identify-title">
                            <x-slot:icon>
                                <flux:icon.qr-code variant="outline" class="size-9" />
                            </x-slot:icon>
                            <label for="visit-manual-code">
                                {{ __('visit-registration.manual_label') }}
                            </label>
                        </x-modal-step-heading>
                        <!-- manual identification -->
                        <form x-on:submit.prevent="findPassManually()" x-bind:hidden="cameraEnabled">
                            <flux:field>
                                <div class="grid grid-cols-1 items-start gap-3 sm:grid-cols-2">
                                    <div class="space-y-2">
                                        <flux:input id="visit-manual-code" name="visit-manual-code" type="text" autocomplete="off" spellcheck="false" placeholder="{{ __('visit-registration.manual_placeholder') }}" x-on:input="manualChanged()" x-bind:autofocus="manualAutofocus" maxlength="256" :aria-invalid="$errorCode !== '' ? 'true' : 'false'" aria-describedby="visit-code-help visit-manual-error visit-manual-transport-error" data-visit-focus class:input="app-input text-center tabular-nums" />
                                        <flux:error id="visit-manual-error" :message="$errorMessage" x-show="!clientErrorCode" class="app-error" />
                                        <flux:error id="visit-manual-transport-error" :message="__('visit-registration.read_unavailable')" :icon="false" x-show="clientErrorCode" x-text="errorMessage" x-cloak class="app-error" />
                                    </div>
                                    <flux:button type="submit" variant="primary" :loading="true" wire:loading.attr="disabled" wire:target="findPass,findVisitReceipt" class="app-button-primary w-full">
                                        {{ __('visit-registration.search') }}
                                    </flux:button>
                                </div>
                                <p id="visit-code-help" class="app-note-with-icon app-role-support text-app-ink-help">
                                    <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                                        <flux:icon.information-circle variant="outline" class="size-4" />
                                    </span>
                                    {{ __('visit-registration.manual_help') }}
                                </p>
                            </flux:field>
                        </form>

                        <!-- camera identification -->
                        <div wire:ignore x-data="qrCameraScanner(token => findPass('token', token), @js($clientFailures['camera']))" x-on:qr-camera-start="startCamera()" x-on:qr-camera-stop="stopCamera()" data-qr-camera class="space-y-4">
                            <div id="visit-camera-region" x-bind:hidden="!cameraEnabled" x-cloak aria-label="{{ __('visit-registration.camera_region') }}" data-qr-camera-region class="space-y-3 rounded-2xl border border-app-border bg-app-control p-4 text-center">
                                <h3 class="app-role-card">
                                    {{ __('visit-registration.instructions') }}
                                </h3>
                                {{-- use opacity instead of display:none to keep mobile camera initialization supported --}}
                                <video muted playsinline x-bind:class="!active && !starting ? 'opacity-0' : 'opacity-100'" aria-hidden="true" class="mx-auto aspect-video w-full rounded-xl object-cover transition-opacity duration-300">
                                </video>
                                <flux:icon.loading wire:loading wire:target="findPass,findVisitReceipt" class="mx-auto size-5" />
                                <flux:error :message="__('visit-registration.read_unavailable')" :icon="false" x-show="errorMessage" x-text="errorMessage" x-cloak class="app-error text-start" />
                            </div>

                            <flux:button id="visit-camera-toggle" type="button" :loading="true" icon="qr-code" icon:variant="outline" wire:target="resetIdentification" x-on:click="$dispatch(cameraEnabled ? 'visit-camera-disable' : 'visit-camera-enable')" x-bind:data-loading="starting" x-bind:class="{ 'pointer-events-auto!': starting && !busy }" x-bind:disabled="busy" x-bind:aria-busy="starting || busy" x-bind:aria-expanded="cameraEnabled" aria-controls="visit-camera-region" data-visit-camera-control class="app-button-secondary w-full">
                                <span x-text="cameraEnabled ? @js(__('visit-registration.camera_stop')) : @js(__('visit-registration.camera_retry'))">
                                </span>
                            </flux:button>
                        </div>
                    </section>
                @elseif ($stage === 'confirm')
                    <section wire:key="visit-confirm" class="space-y-4">
                        <x-modal-step-heading id="visit-confirm-title">
                            <x-slot:icon>
                                <flux:icon.clipboard-document-check variant="outline" class="size-9" />
                            </x-slot:icon>
                            {{ __('visit-registration.confirmation_title') }}
                        </x-modal-step-heading>
                        @if ($preview['promotion'])
                            <x-visit-points-card :promotion="$preview['promotion']" :points="$preview['can_accept_visit'] ? $preview['promotion']['visit_points'] : null" />
                        @endif
                        @if ($preview['can_accept_visit'])
                            <div id="visit-confirm-help" class="app-note-with-icon text-app-ink-help">
                                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                                    <flux:icon.information-circle variant="outline" class="size-4" />
                                </span>
                                <p class="app-role-support">
                                    {{ __('visit-registration.confirmation_help') }}
                                </p>
                            </div>
                        @else
                            <div role="status" class="space-y-2 rounded-2xl border border-app-warning-border bg-app-warning-surface p-4 text-app-warning-ink">
                                @if ($preview['can_redeem_reward'])
                                    <p class="app-role-card">
                                        {{ __('visit-registration.available_reward') }}
                                    </p>
                                    <p class="app-role-support">
                                        {{ __('visit-registration.reward_read_only') }}
                                    </p>
                                @else
                                    <p>
                                        {{ __($preview['promotion'] ? 'visit-registration.completed' : 'visit-registration.no_promotion') }}
                                    </p>
                                @endif
                            </div>
                        @endif
                        <flux:error id="visit-action-error" :message="$errorMessage" x-show="!clientErrorCode" class="app-error" />
                        <flux:error :message="__('visit-registration.unknown')" :icon="false" x-show="clientErrorCode" x-text="errorMessage" x-cloak class="app-error" />
                    </section>
                @elseif ($stage === 'result')
                    <section wire:key="visit-result" class="space-y-4">
                        <x-modal-step-heading id="visit-result-title" :success="true">
                            <x-slot:icon>
                                <flux:icon.check-circle variant="outline" class="size-9" />
                            </x-slot:icon>
                            {{ __('visit-registration.success_title') }}
                            @if ($details && $details['reward_unlocked'])
                                <x-slot:badge>
                                    <flux:badge color="green" role="status" data-visit-reward-unlocked class="app-role-support! relative max-w-full bg-app-success-surface! font-semibold! whitespace-normal! text-app-success-ink!">
                                        <span aria-hidden="true" class="pointer-events-none absolute inset-0 rounded-[inherit] border border-app-success-ink motion-safe:animate-pulse motion-safe:[animation-iteration-count:2]!">
                                        </span>
                                        {{ __('visit-registration.reward_unlocked') }}
                                    </flux:badge>
                                </x-slot:badge>
                            @endif
                        </x-modal-step-heading>
                        <x-visit-points-card :promotion="$details" :points="$facts['points_awarded']" :confirmed="true" />
                        <flux:error id="visit-result-error" :message="$errorMessage" x-show="!clientErrorCode" class="app-error" />
                        <flux:error :message="__('visit-registration.details_unavailable')" :icon="false" x-show="clientErrorCode" x-text="errorMessage" x-cloak class="app-error" />
                    </section>
                @endif
            </div>
        </div>

        <!-- dialog actions -->
        <footer class="flex shrink-0 flex-wrap items-center justify-end gap-3 border-t border-app-line pt-4">
            <flux:modal.close class="me-auto">
                <flux:button type="button" variant="ghost" :loading="false" x-bind:autofocus="!manualAutofocus" aria-describedby="visit-result-title visit-result-error" data-visit-close class="app-button min-h-11">
                    {{ __('visit-registration.close') }}
                </flux:button>
            </flux:modal.close>
            @if (in_array($stage, ['confirm', 'result'], true))
                <flux:button type="button" :loading="true" wire:target="resetIdentification" x-on:click="reset()" x-bind:hidden="!presentationReady" x-bind:disabled="busy || ['pending', 'unknown'].includes(visitPhase)" data-visit-back class="app-button-secondary min-h-11">
                    {{ __('visit-registration.back') }}
                </flux:button>
            @endif
            @if ($stage === 'confirm' && $preview['can_accept_visit'])
                <flux:button type="button" variant="primary" :loading="true" wire:target="acceptVisit,findVisitReceipt" x-bind:hidden="!presentationReady" x-bind:disabled="busy" x-on:click="acceptVisit(@js($preview['promotion']['id']))" aria-describedby="visit-confirm-title visit-confirm-help visit-action-error" data-visit-confirm class="app-button-primary min-h-11">
                    {{ __('visit-registration.confirm_visit') }}
                </flux:button>
            @endif
        </footer>
    </flux:modal>
</fieldset>
