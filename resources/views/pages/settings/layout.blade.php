<div class="flex min-w-0 flex-col gap-6 lg:flex-row lg:items-start lg:gap-8">
    <div class="w-full shrink-0 lg:w-[180px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('business.edit', absolute: false)" wire:navigate class="app-button text-app-ink-secondary! hover:bg-app-secondary-button! data-current:bg-app-emphasis! data-current:text-app-ink-priority!">
                <span class="app-role-action">
                    {{ __('business.profile_title') }}
                </span>
            </flux:navlist.item>
            <flux:navlist.item :href="route('profile.edit', absolute: false)" wire:navigate class="app-button text-app-ink-secondary! hover:bg-app-secondary-button! data-current:bg-app-emphasis! data-current:text-app-ink-priority!">
                <span class="app-role-action">
                    {{ __('Profile') }}
                </span>
            </flux:navlist.item>
            <flux:navlist.item :href="route('security.edit', absolute: false)" wire:navigate class="app-button text-app-ink-secondary! hover:bg-app-secondary-button! data-current:bg-app-emphasis! data-current:text-app-ink-priority!">
                <span class="app-role-action">
                    {{ __('Security') }}
                </span>
            </flux:navlist.item>
        </flux:navlist>
    </div>

    <div data-test="settings-surface" class="w-full min-w-0 max-w-2xl rounded-[20px] border border-app-border bg-app-surface p-4 shadow-lg shadow-black/10 sm:p-6">
        <flux:heading level="3" class="app-role-section! text-app-ink!">
            {{ $heading ?? '' }}
        </flux:heading>
        <flux:subheading class="app-description">
            {{ $subheading ?? '' }}
        </flux:subheading>

        <div class="mt-5 w-full">
            {{ $slot }}
        </div>
    </div>
</div>
