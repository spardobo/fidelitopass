<x-layouts::app :title="__('business.dashboard.page_title')">
    <main class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6">
        <header class="space-y-2">
            <flux:heading size="xl" level="1">{{ __('business.dashboard.heading') }}</flux:heading>
            <flux:text>{{ __('business.dashboard.description') }}</flux:text>
        </header>

        <section aria-label="{{ __('business.profile_title') }}" class="rounded-2xl border border-outline bg-surface p-5">
            <flux:heading level="2">{{ $business->name }}</flux:heading>
            <flux:text>{{ __('business.onboarding.time_zone') }}: {{ $business->timezone }}</flux:text>
            <div class="mt-5">
                <flux:button :href="route('business.edit')" wire:navigate>{{ __('business.dashboard.edit_profile') }}</flux:button>
            </div>
        </section>
    </main>
</x-layouts::app>
