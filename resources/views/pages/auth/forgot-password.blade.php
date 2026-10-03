<x-layouts::auth :title="__('Forgot password')">
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col text-center">
            <flux:heading size="xl" level="1" class="app-heading">
                {{ __('Forgot password') }}
            </flux:heading>
            <flux:subheading class="app-description">
                {{ __('Enter your email to receive a password reset link') }}
            </flux:subheading>
        </div>

        <!-- session status -->
        <x-auth-session-status class="app-status-success text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
            @csrf

            <!-- email address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
                class:input="app-input"
                label:class="app-label"
                error:class="app-error"
            />

            <flux:button variant="primary" type="submit" class="app-button-primary w-full" data-test="email-password-reset-link-button">
                {{ __('Email password reset link') }}
            </flux:button>
        </form>

        <div class="app-role-support app-text-secondary space-x-1 rtl:space-x-reverse text-center">
            <span>{{ __('Or, return to') }}</span>
            <flux:link :href="route('login')" wire:navigate class="app-focus">{{ __('log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
