<div x-data="visitRegistration(@js(\Illuminate\Support\Arr::only(__('visit-registration'), ['read_unavailable', 'unknown', 'details_unavailable'])))" class="app-theme">
    <flux:modal
        name="visit-registration"
        class="app-theme flex max-h-[calc(100dvh-2rem)]! w-[calc(100vw-2rem)] min-w-0! max-w-lg! flex-col overflow-hidden! rounded-[1.25rem]! border border-app-border bg-app-surface! p-4! sm:p-6!"
    >
        <header class="shrink-0 space-y-2 border-b border-app-line pb-4 pe-8">
            <flux:heading level="2" class="app-role-title! text-app-ink!">
                {{ __('visit-registration.title') }}
            </flux:heading>
            <p class="app-role-support text-app-ink-secondary">
                {{ __('visit-registration.introduction') }}
            </p>
        </header>

        <div wire:loading.attr="aria-busy" wire:target="findPass,acceptVisit,findVisitReceipt,resetIdentification" aria-busy="false" class="min-h-0 flex-1 overflow-y-auto py-4 pe-2">
            <div>
                @if ($stage === 'identify')
                    <section wire:key="visit-identify" class="space-y-4">
                        <form x-on:submit.prevent="findPassManually()">
                            <flux:field>
                                <flux:label for="visit-manual-code" class="app-label justify-center text-center sm:justify-start">
                                    {{ __('visit-registration.manual_label') }}
                                </flux:label>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <flux:input
                                        id="visit-manual-code" name="visit-manual-code" type="text"
                                        x-on:input="manualChanged()"
                                        :aria-invalid="$message !== '' ? 'true' : 'false'" aria-describedby="visit-code-help"
                                        autocomplete="off" spellcheck="false" maxlength="256" autofocus data-visit-focus
                                        placeholder="{{ __('visit-registration.manual_placeholder') }}"
                                        class:input="app-input text-center tabular-nums"
                                    />
                                    <flux:button type="submit" variant="primary" :loading="false" wire:loading.attr="disabled" wire:target="findPass" class="app-button-primary w-full">
                                        <flux:icon.loading wire:loading wire:target="findPass" class="size-4" />
                                        <span wire:loading.remove wire:target="findPass">{{ __('visit-registration.search') }}</span>
                                        <span wire:loading wire:target="findPass" role="status">{{ __('visit-registration.searching') }}</span>
                                    </flux:button>
                                </div>
                                <p id="visit-code-help" class="app-note-with-icon app-role-support text-app-ink-help">
                                    <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center">
                                        <flux:icon.information-circle variant="outline" class="size-4" />
                                    </span>
                                    {{ __('visit-registration.manual_help') }}
                                </p>
                            </flux:field>
                        </form>

                        <div wire:ignore class="space-y-4">
                            <flux:button
                                type="button" :loading="false" icon="qr-code" icon:variant="outline"
                                wire:loading.attr="disabled" wire:target="findPass"
                                x-on:click="cameraEnabled ? stopCamera() : requestCameraStart()"
                                x-bind:aria-expanded="cameraEnabled" aria-controls="visit-camera-region"
                                class="app-button-secondary w-full"
                            >
                                <span x-show="!cameraEnabled">{{ __('visit-registration.camera_retry') }}</span>
                                <span x-show="cameraEnabled" x-cloak>{{ __('visit-registration.camera_stop') }}</span>
                            </flux:button>
                            <div
                                id="visit-camera-region"
                                x-data="qrCameraScanner(token => findPass('token', token))"
                                x-on:qr-camera-start="startCamera()" x-on:qr-camera-stop="stopCamera()"
                                x-bind:hidden="!cameraEnabled" x-cloak
                                aria-label="{{ __('visit-registration.camera_region') }}" data-qr-camera data-qr-camera-region
                                class="space-y-3 rounded-2xl border border-app-border bg-app-control p-4 text-center"
                            >
                                <h3 class="app-role-card">{{ __('visit-registration.instructions') }}</h3>
                                {{-- bind visibility before warm SDK startup, without a deferred reveal --}}
                                <video x-bind:hidden="!active && !starting" muted playsinline aria-hidden="true" class="mx-auto aspect-video w-full rounded-xl object-cover"></video>
                                <p x-show="starting" role="status" class="app-role-support text-app-ink-secondary">
                                    <flux:icon.loading class="inline size-4" />
                                    {{ __('visit-registration.camera_starting') }}
                                </p>
                                <p x-show="error" x-text="@js(__('visit-registration.camera_errors'))[error]" role="status" class="app-role-support text-app-warning-ink"></p>
                            </div>
                        </div>
                    </section>
                @elseif ($stage === 'confirm')
                    <section wire:key="visit-confirm" class="space-y-4">
                        <h3 tabindex="-1" data-visit-focus class="app-role-section app-focus">{{ __('visit-registration.confirmation_title') }}</h3>
                        @if ($preview['promotion'])
                            <div class="space-y-4 rounded-2xl border border-app-priority-border bg-app-emphasis p-4">
                                <h4 class="app-role-card break-words">{{ $preview['promotion']['reward_title'] }}</h4>
                                <dl class="grid grid-cols-2 gap-4">
                                    <div class="space-y-1">
                                        <dt class="app-role-support text-app-ink-secondary">{{ __('visit-registration.progress') }}</dt>
                                        <dd class="app-role-section tabular-nums">{{ $preview['promotion']['earned_points'] }} / {{ $preview['promotion']['target_points'] }} <span class="app-role-support">{{ __('visit-registration.points') }}</span></dd>
                                    </div>
                                    @if ($preview['can_accept_visit'])
                                        <div class="space-y-1">
                                            <dt class="app-role-support text-app-ink-secondary">{{ __('visit-registration.visit_points') }}</dt>
                                            <dd class="app-role-section text-app-ink-priority">+{{ $preview['promotion']['visit_points'] }} <span class="app-role-support">{{ __('visit-registration.points') }}</span></dd>
                                        </div>
                                    @endif
                                </dl>
                            </div>
                        @endif
                        @if ($preview['can_accept_visit'])
                            <p class="app-role-support text-app-ink-help">{{ __('visit-registration.confirmation_help') }}</p>
                        @else
                            <div role="status" class="space-y-2 rounded-2xl border border-app-warning-border bg-app-warning-surface p-4 text-app-warning-ink">
                                @if ($preview['can_redeem_reward'])
                                    <p class="app-role-card">{{ __('visit-registration.available_reward') }}</p>
                                    <p class="app-role-support">{{ __('visit-registration.reward_read_only') }}</p>
                                @else
                                    <p>{{ __($preview['promotion'] ? 'visit-registration.completed' : 'visit-registration.no_promotion') }}</p>
                                @endif
                            </div>
                        @endif
                        <p wire:loading wire:target="acceptVisit,findVisitReceipt" role="status" class="app-role-support">{{ __('visit-registration.pending') }}</p>
                    </section>
                @elseif ($stage === 'result')
                    <section wire:key="visit-result" class="space-y-4">
                        <div class="space-y-4 rounded-2xl border border-app-success-border bg-app-success-surface p-4 text-app-success-ink">
                            <div class="flex items-center gap-3">
                                <flux:icon.check-circle variant="outline" class="size-8 shrink-0" />
                                <h3 tabindex="-1" data-visit-focus class="app-role-section app-focus">{{ __('visit-registration.success_title') }}</h3>
                            </div>
                            <dl class="space-y-3">
                                <div class="space-y-1">
                                    <dt class="app-role-support">{{ __('visit-registration.awarded') }}</dt>
                                    <dd class="app-role-metric">+{{ $facts['points_awarded'] }} <span class="app-role-body">{{ __('visit-registration.points') }}</span></dd>
                                </div>
                                @if ($details)
                                    <div class="space-y-1">
                                        <dt class="app-role-support">{{ __('visit-registration.updated_progress') }}</dt>
                                        <dd class="app-role-section tabular-nums">{{ $details['earned_points'] }} / {{ $details['target_points'] }} <span class="app-role-support">{{ __('visit-registration.points') }}</span></dd>
                                    </div>
                                @endif
                            </dl>
                            @if ($details)
                                <div class="space-y-1 break-words">
                                    @if ($details['reward_unlocked'])
                                        <p class="app-role-action">{{ __('visit-registration.reward_unlocked') }}</p>
                                    @endif
                                    <h4 class="app-role-card">{{ $details['reward_title'] }}</h4>
                                    <p class="app-role-support">{{ $details['reward_description'] }}</p>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif
            </div>

            <p wire:loading wire:target="resetIdentification" role="status" class="app-role-support">{{ __('visit-registration.resetting') }}</p>
        </div>

        <footer class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-app-line pt-4">
            @if (in_array($stage, ['confirm', 'result'], true))
                <flux:button
                    type="button" wire:loading.attr="disabled" wire:target="resetIdentification,acceptVisit"
                    x-on:click="reset()" x-bind:disabled="busy || ['pending', 'unknown'].includes(visitPhase)"
                    class="app-button-secondary grow sm:grow-0"
                >
                    {{ __('visit-registration.back') }}
                </flux:button>
            @endif
            <flux:modal.close class="contents">
                <flux:button type="button" class="app-button-secondary grow sm:grow-0">
                    {{ __('visit-registration.close') }}
                </flux:button>
            </flux:modal.close>
            @if ($stage === 'confirm' && $preview['can_accept_visit'])
                <flux:button
                    type="button" variant="primary" :loading="true"
                    wire:loading.attr="disabled" wire:target="acceptVisit,findVisitReceipt"
                    x-on:click="acceptVisit(@js($preview['promotion']['id']))"
                    class="app-button-primary grow sm:grow-0"
                >
                    {{ __('visit-registration.confirm_visit') }}
                </flux:button>
            @endif
        </footer>
    </flux:modal>
</div>
