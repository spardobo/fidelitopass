<div x-data="visitRegistration(@js(\Illuminate\Support\Arr::only(__('visit-registration'), ['read_unavailable', 'unknown', 'details_unavailable', 'error_notice', 'details_notice'])))" x-on:visit-camera-enable="requestCameraStart()" x-on:visit-camera-disable="reset(true)" x-on:visit-camera-error="focusStage()" class="app-theme">
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

        <div wire:loading.attr="aria-busy" wire:target="findPass,acceptVisit,findVisitReceipt,resetIdentification" aria-busy="false" class="-mx-2 min-h-0 flex-1 overflow-y-auto px-2 py-4">
            <div>
                @if ($stage === 'identify')
                    <section wire:key="visit-identify" class="space-y-4">
                        <form x-on:submit.prevent="findPassManually()" x-bind:hidden="cameraEnabled">
                            <flux:field>
                                <flux:label for="visit-manual-code" class="app-label justify-center text-center sm:justify-start">
                                    {{ __('visit-registration.manual_label') }}
                                </flux:label>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <flux:input
                                        id="visit-manual-code" name="visit-manual-code" type="text"
                                        x-on:input="manualChanged()"
                                        :aria-invalid="$message !== '' ? 'true' : 'false'" aria-describedby="visit-code-help visit-manual-error visit-manual-transport-error"
                                        autocomplete="off" spellcheck="false" maxlength="256" x-bind:autofocus="manualAutofocus" data-visit-focus
                                        placeholder="{{ __('visit-registration.manual_placeholder') }}"
                                        class:input="app-input text-center tabular-nums"
                                    />
                                    <flux:button type="submit" variant="primary" :loading="true" wire:loading.attr="disabled" wire:target="findPass" class="app-button-primary w-full">
                                        {{ __('visit-registration.search') }}
                                    </flux:button>
                                </div>
                                <flux:error id="visit-manual-error" :message="$message" x-show="!transportError" class="app-error" />
                                <flux:error id="visit-manual-transport-error" :message="__('visit-registration.read_unavailable')" :icon="false" x-show="transportError" x-text="transportError" x-cloak class="app-error" />
                                <p id="visit-code-help" class="app-note-with-icon app-role-support text-app-ink-help">
                                    <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                                        <flux:icon.information-circle variant="outline" class="size-4" />
                                    </span>
                                    {{ __('visit-registration.manual_help') }}
                                </p>
                            </flux:field>
                        </form>

                        <div
                            wire:ignore x-data="qrCameraScanner(token => findPass('token', token))"
                            x-on:qr-camera-start="startCamera()" x-on:qr-camera-stop="stopCamera()"
                            x-effect="if (error) $dispatch('visit-camera-error')"
                            data-qr-camera class="space-y-4"
                        >
                            <div
                                id="visit-camera-region" x-bind:hidden="!cameraEnabled" x-cloak
                                aria-label="{{ __('visit-registration.camera_region') }}" data-qr-camera-region
                                class="space-y-3 rounded-2xl border border-app-border bg-app-control p-4 text-center"
                            >
                                <h3 class="app-role-card">{{ __('visit-registration.instructions') }}</h3>
                                {{-- bind visibility before warm SDK startup, without a deferred reveal --}}
                                <video x-bind:hidden="!active && !starting" muted playsinline aria-hidden="true" class="mx-auto aspect-video w-full rounded-xl object-cover"></video>
                                <flux:icon.loading wire:loading wire:target="findPass" class="mx-auto size-5" />
                                <flux:error
                                    :message="__('visit-registration.read_unavailable')" :icon="false"
                                    x-show="error || transportError || $wire.message"
                                    :x-text="'transportError || $wire.message || '.\Illuminate\Support\Js::from(__('visit-registration.camera_errors')).'[error]'"
                                    x-cloak class="app-error text-start"
                                />
                            </div>

                            <flux:button
                                id="visit-camera-toggle" type="button" :loading="true" icon="qr-code" icon:variant="outline"
                                wire:target="resetIdentification"
                                x-on:click="$dispatch(cameraEnabled ? 'visit-camera-disable' : 'visit-camera-enable')"
                                x-bind:data-loading="starting" x-bind:class="{ 'pointer-events-auto!': starting && !busy }"
                                x-bind:disabled="busy" x-bind:aria-busy="starting || busy"
                                x-bind:aria-expanded="cameraEnabled" aria-controls="visit-camera-region"
                                data-visit-camera-control class="app-button-secondary w-full"
                            >
                                <span x-text="cameraEnabled ? @js(__('visit-registration.camera_stop')) : @js(__('visit-registration.camera_retry'))"></span>
                            </flux:button>
                        </div>
                    </section>
                @elseif ($stage === 'confirm')
                    <section wire:key="visit-confirm" class="space-y-4">
                        <h3 id="visit-confirm-title" class="app-role-section">{{ __('visit-registration.confirmation_title') }}</h3>
                        @if ($preview['promotion'])
                            <div class="space-y-4 rounded-2xl border border-app-border bg-app-control p-4">
                                <div id="visit-reward" class="flex items-center gap-2">
                                    <flux:icon.gift variant="outline" class="size-5 shrink-0 text-app-accent" />
                                    <h4 class="app-role-card break-words text-app-ink">{{ $preview['promotion']['reward_title'] }}</h4>
                                </div>
                                <dl class="grid grid-cols-2 gap-4">
                                    <div class="space-y-1">
                                        <dt id="visit-progress-label" class="app-role-support text-app-ink-secondary">{{ __('visit-registration.progress') }}</dt>
                                        <dd class="app-role-section tabular-nums text-app-ink">{{ $preview['promotion']['earned_points'] }} / {{ $preview['promotion']['target_points'] }} <span class="app-role-support text-app-ink-secondary">{{ __('visit-registration.points') }}</span></dd>
                                    </div>
                                    @if ($preview['can_accept_visit'])
                                        <div class="space-y-1">
                                            <dt class="app-role-support text-app-ink-secondary">{{ __('visit-registration.visit_points') }}</dt>
                                            <dd id="visit-points-gain" class="app-role-metric text-app-ink">+{{ $preview['promotion']['visit_points'] }} <span class="app-role-support text-app-ink-secondary">{{ __('visit-registration.points') }}</span></dd>
                                        </div>
                                    @endif
                                </dl>
                                <flux:progress
                                    id="visit-progress" :value="min($preview['promotion']['earned_points'], $preview['promotion']['target_points'])"
                                    :max="$preview['promotion']['target_points']" aria-labelledby="visit-progress-label"
                                    :aria-valuetext="__('visit-registration.progress_value', ['earned' => $preview['promotion']['earned_points'], 'target' => $preview['promotion']['target_points']])"
                                    class="h-2"
                                />
                            </div>
                        @endif
                        @if ($preview['can_accept_visit'])
                            <div id="visit-confirm-help" class="app-note-with-icon text-app-ink-help">
                                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                                    <flux:icon.information-circle variant="outline" class="size-4" />
                                </span>
                                <p class="app-role-support">{{ __('visit-registration.confirmation_help') }}</p>
                            </div>
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
                        <flux:error id="visit-action-error" :message="$message" x-show="!transportError" class="app-error" />
                        <flux:error :message="__('visit-registration.unknown')" :icon="false" x-show="transportError" x-text="transportError" x-cloak class="app-error" />
                    </section>
                @elseif ($stage === 'result')
                    <section wire:key="visit-result" class="space-y-4">
                        <div class="flex items-center gap-3 text-app-success-ink">
                            <flux:icon.check-circle variant="outline" class="size-10 shrink-0" />
                            <h3 id="visit-result-title" class="app-role-section">{{ __('visit-registration.success_title') }}</h3>
                        </div>
                        <div class="space-y-4 rounded-2xl border border-app-border bg-app-control p-4">
                            <dl class="space-y-4">
                                <div class="space-y-1">
                                    <dt class="app-role-support text-app-ink-secondary">{{ __('visit-registration.awarded') }}</dt>
                                    <dd id="visit-points-gain" class="app-role-metric text-app-ink">+{{ $facts['points_awarded'] }} <span class="app-role-support text-app-ink-secondary">{{ __('visit-registration.points') }}</span></dd>
                                </div>
                                @if ($details)
                                    <div class="space-y-2">
                                        <dt id="visit-progress-label" class="app-role-support text-app-ink-secondary">{{ __('visit-registration.updated_progress') }}</dt>
                                        <dd class="space-y-2">
                                            <p class="app-role-section tabular-nums text-app-ink">{{ $details['earned_points'] }} / {{ $details['target_points'] }} <span class="app-role-support text-app-ink-secondary">{{ __('visit-registration.points') }}</span></p>
                                            <flux:progress
                                                id="visit-progress" :value="min($details['earned_points'], $details['target_points'])"
                                                :max="$details['target_points']" aria-labelledby="visit-progress-label"
                                                :aria-valuetext="__('visit-registration.progress_value', ['earned' => $details['earned_points'], 'target' => $details['target_points']])"
                                                class="h-2"
                                            />
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                            @if ($details)
                                <div class="space-y-1 break-words border-t border-app-line pt-4">
                                    @if ($details['reward_unlocked'])
                                        <p class="app-role-action text-app-success-ink">{{ __('visit-registration.reward_unlocked') }}</p>
                                    @endif
                                    <div id="visit-reward" class="flex items-center gap-2">
                                        <flux:icon.gift variant="outline" class="size-5 shrink-0 text-app-accent" />
                                        <h4 class="app-role-card text-app-ink">{{ $details['reward_title'] }}</h4>
                                    </div>
                                    <p class="app-role-support text-app-ink-secondary">{{ $details['reward_description'] }}</p>
                                </div>
                            @endif
                        </div>
                    </section>
                    <flux:error id="visit-result-error" :message="$message" x-show="!transportError" class="app-error" />
                    <flux:error :message="__('visit-registration.details_unavailable')" :icon="false" x-show="transportError" x-text="transportError" x-cloak class="app-error" />
                @endif
            </div>

        </div>

        <footer class="flex shrink-0 flex-wrap items-center gap-3 border-t border-app-line pt-4">
            @if (in_array($stage, ['confirm', 'result'], true))
                <flux:button
                    type="button" :loading="true" wire:target="resetIdentification"
                    x-on:click="reset()" x-bind:disabled="busy || ['pending', 'unknown'].includes(visitPhase)"
                    data-visit-back :class="'app-button-secondary grow sm:grow-0'.(($stage === 'result' || ($preview['can_accept_visit'] ?? false)) ? ' me-auto' : '')"
                >
                    {{ __('visit-registration.back') }}
                </flux:button>
            @endif
            <flux:modal.close class="contents">
                <flux:button
                    type="button" :variant="$stage === 'result' ? 'primary' : 'outline'" :loading="false"
                    x-bind:autofocus="!manualAutofocus" data-visit-close
                    aria-describedby="visit-result-title visit-result-error"
                    :class="($stage === 'result' ? 'app-button-primary' : 'app-button-secondary').' grow sm:grow-0'"
                >
                    {{ __('visit-registration.close') }}
                </flux:button>
            </flux:modal.close>
            @if ($stage === 'confirm' && $preview['can_accept_visit'])
                <flux:button
                    type="button" variant="primary" :loading="true"
                    wire:target="acceptVisit,findVisitReceipt" x-bind:disabled="busy"
                    x-on:click="acceptVisit(@js($preview['promotion']['id']))"
                    data-visit-confirm aria-describedby="visit-confirm-title visit-confirm-help visit-action-error" class="app-button-primary grow sm:grow-0"
                >
                    {{ __('visit-registration.confirm_visit') }}
                </flux:button>
            @endif
        </footer>
    </flux:modal>
</div>
