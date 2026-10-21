<fieldset wire:loading.attr="disabled" wire:target="findPass,acceptVisit,findVisitReceipt,redeemReward,findRewardReceipt,resetIdentification" x-data="visitRegistration(@js($clientFailures))" x-on:visit-camera-enable="requestCameraStart()" x-on:visit-camera-disable="reset(true)" x-on:qr-camera-fatal-error="cameraFailed($event.detail)" x-on:livewire-transport-error="logTransportError($event.detail)" x-bind:disabled="busy" x-bind:aria-busy="busy" x-bind:data-focus-modality="focusModality" class="app-theme contents [&[data-focus-modality=pointer]_:is(button,input):focus]:outline-none! [&[data-focus-modality=pointer]_input:focus]:ring-0! [&[data-focus-modality=pointer]_input:focus]:shadow-none! [&[data-focus-modality=pointer]_input:not([data-invalid]):focus]:border-app-control-border! [&[data-focus-modality=keyboard]_button:focus]:outline-solid! [&[data-focus-modality=keyboard]_button:focus]:outline-[length:var(--app-focus-width)]! [&[data-focus-modality=keyboard]_button:focus]:outline-app-accent-text! [&[data-focus-modality=keyboard]_button:focus]:outline-offset-[var(--app-focus-offset)]!">
    <flux:modal name="visit-registration" :dismissible="false" class="app-theme flex max-h-[calc(100dvh-2rem)]! w-[calc(100vw-2rem)] min-w-0! max-w-2xl! flex-col overflow-hidden! rounded-[1.25rem]! border border-app-border bg-app-surface! p-4! sm:p-6! [&_[data-flux-modal-close]_button]:min-h-11 [&_[data-flux-modal-close]_button]:min-w-11">
        <header class="shrink-0 space-y-4 border-b border-app-line pb-4">
            <div class="flex items-center justify-between gap-4 pr-12">
                <flux:heading level="2" class="app-role-title! text-app-ink!">
                    {{ __('visit-registration.title') }}
                </flux:heading>
            </div>
            @php
                $steps = ['identify', 'confirm', 'result'];
                $currentStepIndex = array_search($stage, $steps, true);
            @endphp
            <ol aria-label="{{ __('visit-registration.steps') }}" data-visit-steps class="app-role-support flex justify-between gap-3 text-app-ink-secondary">
                @foreach ($steps as $index => $step)
                    @php
                        $completed = $index < $currentStepIndex;
                        $current = $index === $currentStepIndex;
                    @endphp
                    <li @if ($current) aria-current="step" @endif @class([
                        'flex items-center gap-2',
                        'text-app-accent' => $completed || $current,
                        'font-bold' => $current,
                    ])>
                        <span aria-hidden="true" @class([
                            'flex size-6 items-center justify-center rounded-full border',
                            'border-app-accent bg-app-accent font-bold text-app-on-accent' => $current,
                            'border-app-priority-border text-app-accent' => $completed,
                            'border-app-line' => ! $completed && ! $current,
                        ])>
                            @if ($completed)
                                <flux:icon.check variant="outline" class="size-4" />
                            @else
                                {{ $index + 1 }}
                            @endif
                        </span>
                        {{ __('visit-registration.'.$step) }}
                        @if ($completed)
                            <span class="sr-only">
                                {{ __('visit-registration.step_completed') }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </header>

        <div wire:loading.attr="aria-busy" wire:target="findPass,acceptVisit,findVisitReceipt,redeemReward,findRewardReceipt,resetIdentification" aria-busy="false" class="-mx-2 min-h-0 flex-1 overflow-y-auto px-2 py-4">
            <!-- preparation feedback -->
            <div x-bind:hidden="presentationReady || !busy" x-cloak class="app-note-with-icon app-role-support text-app-ink-secondary">
                <flux:icon.loading class="size-5 shrink-0" />
                {{ __('visit-registration.resetting') }}
            </div>
            <div x-bind:hidden="presentationReady || busy || !clientErrorCode" x-cloak class="space-y-4">
                <flux:error :message="__('visit-registration.read_unavailable')" :icon="false" x-text="errorMessage" class="app-error" />
            </div>
            <div x-bind:hidden="!presentationReady">
                @if ($stage !== 'identify')
                    <button type="button" x-on:click="reset()" x-bind:disabled="busy || cameraStarting || ['pending', 'unknown'].includes(visitPhase)" x-bind:aria-busy="resettingReading" data-visit-back class="app-button relative mb-4 inline-flex min-h-11 items-center gap-2 text-sm font-medium text-app-accent hover:underline disabled:opacity-50">
                        <span x-bind:class="{ 'opacity-0': resettingReading }" class="inline-flex items-center gap-2">
                            <flux:icon.arrow-left variant="outline" class="size-4" />
                            {{ __('visit-registration.back') }}
                        </span>
                        <div x-show="resettingReading" x-cloak data-flux-loading-indicator class="absolute inset-0 flex items-center justify-center">
                            <flux:icon.loading class="size-4" />
                        </div>
                    </button>
                @endif
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
                                        <flux:input id="visit-manual-code" name="visit-manual-code" type="text" autocomplete="off" spellcheck="false" placeholder="{{ __('visit-registration.manual_placeholder') }}" x-on:input="manualChanged()" x-bind:autofocus="manualAutofocus" x-bind:disabled="busy || cameraStarting" maxlength="256" :aria-invalid="$errorCode !== '' ? 'true' : 'false'" aria-describedby="visit-code-help visit-manual-error visit-manual-transport-error" data-visit-focus class:input="app-input text-center tabular-nums" />
                                        <flux:error id="visit-manual-error" :message="$errorMessage" x-show="!clientErrorCode" class="app-error" />
                                        <flux:error id="visit-manual-transport-error" :message="__('visit-registration.read_unavailable')" :icon="false" x-show="clientErrorCode" x-text="errorMessage" x-cloak class="app-error" />
                                    </div>
                                    <flux:button type="button" variant="primary" :loading="true" wire:loading.attr="aria-busy" wire:target="findPass,findVisitReceipt,findRewardReceipt" x-on:click="findPassManually()" x-bind:data-loading="searchingPass" x-bind:disabled="busy || cameraStarting" data-visit-search class="app-button-primary w-full">
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
                                {{-- reserve the proportional frame and keep the video mounted during native startup --}}
                                <div data-visit-camera-frame class="relative mx-auto aspect-video w-full max-w-[min(320px,53.333dvh)] sm:max-w-[min(384px,53.333dvh)]">
                                    <video muted playsinline x-bind:class="active ? 'opacity-100' : 'opacity-0'" aria-hidden="true" class="absolute inset-0 h-full w-full rounded-xl object-contain">
                                    </video>
                                    <div x-show="!active" data-visit-camera-placeholder class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-app-ink-secondary">
                                        <flux:icon.camera variant="outline" class="size-9" />
                                        <p x-show="starting" class="app-role-support">{{ __('visit-registration.camera_starting') }}</p>
                                    </div>
                                </div>
                                <flux:icon.loading wire:loading wire:target="findPass,findVisitReceipt,findRewardReceipt" class="mx-auto size-5" />
                                <flux:error :message="__('visit-registration.read_unavailable')" :icon="false" x-show="errorMessage" x-text="errorMessage" x-cloak class="app-error text-start" />
                            </div>

                            <flux:button id="visit-camera-toggle" type="button" :loading="true" icon="qr-code" icon:variant="outline" wire:loading.attr="aria-busy" wire:target="resetIdentification" x-on:click="$dispatch(cameraEnabled ? 'visit-camera-disable' : 'visit-camera-enable')" x-bind:data-loading="starting || resettingCamera" x-bind:class="{ 'pointer-events-auto!': starting && !busy }" x-bind:disabled="busy" x-bind:aria-busy="starting || busy" x-bind:aria-expanded="cameraEnabled" aria-controls="visit-camera-region" data-visit-camera-control class="app-button-secondary w-full">
                                <span x-text="cameraEnabled ? @js(__('visit-registration.camera_stop')) : @js(__('visit-registration.camera_retry'))">
                                </span>
                            </flux:button>
                        </div>
                    </section>
                @elseif ($stage === 'confirm')
                    <section wire:key="visit-confirm" class="space-y-4">
                        <x-modal-step-heading id="visit-confirm-title">
                            <x-slot:icon>
                                <flux:icon.check-circle variant="outline" class="size-9" />
                            </x-slot:icon>
                            {{ $confirmationTitle }}
                        </x-modal-step-heading>
                        @if ($cardPromotion)
                            <x-visit-points-card :promotion="$cardPromotion" :points="$cardPoints" />
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
                                    @if ($reward)
                                        <p class="app-role-support">
                                            {{ __('visit-registration.reward_deadline', ['date' => $reward['promotion']['deadline']]) }}
                                        </p>
                                    @endif
                                @else
                                    <p>
                                        {{ $unavailableMessage }}
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
                            {{ $resultTitle }}
                        </x-modal-step-heading>
                        @if ($cardPromotion !== null || $cardPoints !== null)
                            <x-visit-points-card :promotion="$cardPromotion" :points="$cardPoints" :confirmed="true" />
                        @endif
                        <flux:error id="visit-result-error" :message="$errorMessage" x-show="!clientErrorCode" class="app-error" />
                        <flux:error :message="__('visit-registration.details_unavailable')" :icon="false" x-show="clientErrorCode" x-text="errorMessage" x-cloak class="app-error" />
                    </section>
                @endif
            </div>
        </div>

        <!-- dialog actions -->
        <footer class="flex shrink-0 items-start justify-between gap-3 border-t border-app-line pt-4">
            <flux:modal.close>
                <flux:button type="button" variant="ghost" :loading="false" x-bind:autofocus="!manualAutofocus" x-bind:disabled="busy || cameraStarting" data-visit-close class="app-button min-h-11 shrink-0">
                    {{ __('visit-registration.close') }}
                </flux:button>
            </flux:modal.close>
            @if ($stage === 'confirm')
                <div x-bind:hidden="!presentationReady" class="flex min-w-0 flex-wrap justify-end gap-3 [&_button]:h-auto! [&_button]:min-h-11 [&_button]:max-w-full [&_button]:whitespace-normal!">
                    @if ($preview['can_accept_visit'])
                        <flux:button type="button" :variant="$projectsReward ? 'outline' : 'primary'" :loading="true" wire:target="acceptVisit,findVisitReceipt" x-bind:disabled="busy" x-on:click="acceptVisit({{ $preview['promotion']['id'] }})" aria-describedby="visit-confirm-title visit-confirm-help visit-action-error" data-visit-confirm :class="$projectsReward ? 'app-button-secondary min-h-11' : 'app-button-primary min-h-11'">
                            {{ __($projectsReward ? 'visit-registration.visit_only' : 'visit-registration.confirm_visit') }}
                        </flux:button>
                    @endif
                    @if ($redemptionKind === 'combined')
                        <flux:button type="button" variant="primary" :loading="true" wire:target="acceptVisit,findVisitReceipt" x-on:click="acceptVisit({{ $preview['promotion']['id'] }}, true)" x-bind:disabled="busy" data-reward-redeem class="app-button-primary min-h-11">
                            {{ __('visit-registration.register_redeem') }}
                        </flux:button>
                    @elseif ($redemptionKind === 'reward')
                        <flux:button type="button" variant="primary" :loading="true" wire:target="redeemReward,findRewardReceipt" x-on:click="redeemReward()" x-bind:disabled="busy" data-reward-redeem class="app-button-primary min-h-11">
                            {{ __('visit-registration.redeem') }}
                        </flux:button>
                    @endif
                </div>
            @endif
        </footer>
    </flux:modal>
</fieldset>
