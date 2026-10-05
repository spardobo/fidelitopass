<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col text-center">
            <flux:heading size="xl" level="1" class="app-heading">
                {{ __('Create an account') }}
            </flux:heading>
            <flux:subheading class="app-description">
                {{ __('auth.registration.description') }}
            </flux:subheading>
        </div>

        <!-- session status -->
        <x-auth-session-status class="app-status-success text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
                class:input="app-input"
                label:class="app-label"
                error:class="app-error"
            />

            <!-- email address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
                class:input="app-input"
                label:class="app-label"
                error:class="app-error"
            />

            {{-- business details --}}
            <flux:input
                name="business_name"
                type="text"
                :label="__('business.fields.business_name')"
                :value="old('business_name')"
                required
                autocomplete="organization"
                maxlength="255"
                class:input="app-input"
                label:class="app-label"
                error:class="app-error"
            />

            @php
                $timezoneChoices = \App\Support\TimezoneLabel::choices(app()->getLocale());
                $selectedTimezone = old('timezone', '');
                $selectedTimezoneName = is_string($selectedTimezone) ? ($timezoneChoices[$selectedTimezone]['name'] ?? '') : '';
            @endphp

            <flux:field x-data="{ timezoneName: @js($selectedTimezoneName) }">
                <flux:label class="app-label">
                    {{ __('business.fields.time_zone') }}
                </flux:label>
                <flux:select
                    name="timezone"
                    x-init="
                        if ($el.dataset.hasOldInput !== 'true' && $el.value === '') {
                            try {
                                const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
                                const supported = Array.from($el.options).some(option => option.value === zone);

                                if (supported) $el.value = zone;
                            } catch {
                                // timezone discovery is optional; keep manual selection available.
                            }
                        }

                        timezoneName = $el.selectedOptions[0]?.dataset.timezoneName ?? '';
                    "
                    x-on:change="timezoneName = $el.selectedOptions[0]?.dataset.timezoneName ?? ''"
                    aria-describedby="registration-timezone-name registration-timezone-guidance"
                    required
                    :data-has-old-input="session()->hasOldInput() ? 'true' : 'false'"
                    class="app-input"
                >
                    <flux:select.option value="" :selected="old('timezone', '') === ''">
                        {{ __('business.fields.select_time_zone') }}
                    </flux:select.option>
                    @foreach ($timezoneChoices as $identifier => $choice)
                        <flux:select.option :value="$identifier" :selected="old('timezone') === $identifier" :data-timezone-name="$choice['name']">
                            {{ $choice['label'] }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description
                    id="registration-timezone-name"
                    x-show="timezoneName !== ''"
                    aria-live="polite"
                    @style(['display: none' => $selectedTimezoneName === ''])
                >
                    <flux:badge x-text="timezoneName" class="max-w-full whitespace-normal! bg-app-emphasis! text-app-ink-priority!">
                        {{ $selectedTimezoneName }}
                    </flux:badge>
                </flux:description>
                <flux:error name="timezone" class="app-error" />
                <flux:description id="registration-timezone-guidance" class="app-role-support! text-app-ink-help!">
                    {{ __('auth.registration.timezone_help') }}
                </flux:description>
            </flux:field>

            <!-- password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                class:input="app-input-password"
                label:class="app-label"
                error:class="app-error"
            >
                <x-slot name="iconTrailing">
                    <flux:input.viewable class="app-button-toggle" />
                </x-slot>
            </flux:input>

            <!-- confirm password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                class:input="app-input-password"
                label:class="app-label"
                error:class="app-error"
            >
                <x-slot name="iconTrailing">
                    <flux:input.viewable class="app-button-toggle" />
                </x-slot>
            </flux:input>

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="app-button-primary w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="app-role-support app-text-secondary space-x-1 rtl:space-x-reverse text-center">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate class="app-focus">{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
