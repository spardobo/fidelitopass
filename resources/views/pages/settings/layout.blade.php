<div data-test="settings-surface" class="flex min-w-0 flex-col gap-6 rounded-2xl border border-white/10 bg-[#272727] p-5 shadow-lg shadow-black/10 sm:p-8 md:flex-row md:items-start md:gap-8">
    <div class="w-full shrink-0 md:w-[180px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="min-w-0 flex-1 self-stretch">
        <flux:heading level="3">{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
