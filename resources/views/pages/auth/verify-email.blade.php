<x-layouts::auth :title="__('Email verification')">
    <div x-data="{ pending: null }" x-on:pageshow.window="pending = null" class="mt-4 flex flex-col gap-6">
        <flux:text class="app-description text-center">
            {{ __('Please verify your email address by clicking on the link we just emailed to you.') }}
        </flux:text>

        @if (session('status') == 'verification-link-sent')
            <flux:text class="app-status-success text-center">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </flux:text>
        @endif

        <div class="flex flex-col items-center justify-between space-y-3">
            <form method="POST" action="{{ route('verification.send') }}" x-on:submit="if (pending) { $event.preventDefault() } else { pending = 'resend' }">
                @csrf
                <flux:button
                    type="submit"
                    variant="primary"
                    :loading="false"
                    x-bind:disabled="pending !== null"
                    x-bind:aria-busy="pending === 'resend'"
                    class="app-button-primary w-full"
                >
                    <flux:icon.loading x-show="pending === 'resend'" x-cloak variant="micro" aria-hidden="true" data-pending-spinner />
                    {{ __('Resend verification email') }}
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}" x-on:submit="if (pending) { $event.preventDefault() } else { pending = 'logout' }">
                @csrf
                <flux:button
                    variant="ghost"
                    type="submit"
                    :loading="false"
                    x-bind:disabled="pending !== null"
                    x-bind:aria-busy="pending === 'logout'"
                    data-test="logout-button"
                    class="app-button cursor-pointer"
                >
                    <flux:icon.loading x-show="pending === 'logout'" x-cloak variant="micro" aria-hidden="true" data-pending-spinner />
                    {{ __('Log out') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
