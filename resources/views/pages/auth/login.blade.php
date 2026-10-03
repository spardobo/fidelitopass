<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col text-center">
            <flux:heading size="xl" level="1" class="app-heading">
                {{ __('Log in to your account') }}
            </flux:heading>
            <flux:subheading class="app-description">
                {{ __('Enter your email and password below to log in') }}
            </flux:subheading>
        </div>

        <!-- session status -->
        <x-auth-session-status class="app-status-success text-center" :status="session('status')" />

        {{-- @chisel-passkeys --}}
        @if (\Laravel\Fortify\Features::enabled(\Laravel\Fortify\Features::passkeys()))
            <x-passkey-verify />
        @endif
        {{-- @end-chisel-passkeys --}}

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- email address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
                class:input="app-input"
                label:class="app-label"
                error:class="app-error"
            />

            <!-- password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    class:input="app-input-password"
                    label:class="app-label"
                    error:class="app-error"
                >
                    <x-slot name="iconTrailing">
                        <flux:input.viewable class="app-button-toggle" />
                    </x-slot>
                </flux:input>

                @if (Route::has('password.request'))
                    <flux:link class="app-focus absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- remember me -->
            <flux:field variant="inline" class="app-choice-field">
                <flux:checkbox name="remember" :checked="old('remember')" class="app-focus" />
                <flux:label class="app-label">
                    {{ __('Remember me') }}
                </flux:label>
                <flux:error name="remember" class="app-error" />
            </flux:field>

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="app-button-primary w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        {{-- @chisel-registration --}}
        <div class="app-role-support app-text-secondary space-x-1 text-center rtl:space-x-reverse">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate class="app-focus">{{ __('Sign up') }}</flux:link>
        </div>
        {{-- @end-chisel-registration --}}
    </div>
</x-layouts::auth>
