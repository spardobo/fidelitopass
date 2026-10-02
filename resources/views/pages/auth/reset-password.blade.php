<x-layouts::auth :title="__('Reset password')">
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col text-center">
            <flux:heading size="xl" level="1" class="app-heading">
                {{ __('Reset password') }}
            </flux:heading>
            <flux:subheading class="app-description">
                {{ __('Please enter your new password below') }}
            </flux:subheading>
        </div>

        <!-- session status -->
        <x-auth-session-status class="app-status-success text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6">
            @csrf
            <!-- token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <!-- email address -->
            <flux:input
                name="email"
                value="{{ request('email') }}"
                :label="__('Email')"
                type="email"
                required
                autocomplete="email"
                class:input="app-input"
                label:class="app-label"
                error:class="app-error"
            />

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
                <flux:button type="submit" variant="primary" class="app-button-primary w-full" data-test="reset-password-button">
                    {{ __('Reset password') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
