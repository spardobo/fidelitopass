<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col text-center">
            <flux:heading size="xl" level="1" class="app-heading">
                {{ __('Create an account') }}
            </flux:heading>
            <flux:subheading class="app-description">
                {{ __('Enter your details below to create your account') }}
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
