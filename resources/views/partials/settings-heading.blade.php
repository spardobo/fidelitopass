<header class="w-full space-y-2">
    @if ($businessName ?? null)
        <p class="app-role-body! text-app-accent">
            {{ $businessName }} · {{ __('business.pass.business_label') }}
        </p>
    @endif
    <flux:heading size="xl" level="1" class="app-heading">
        {{ __('Settings') }}
    </flux:heading>
    <flux:subheading class="app-role-intro! text-app-ink-secondary!">
        {{ __('Manage your profile and account settings') }}
    </flux:subheading>
</header>
