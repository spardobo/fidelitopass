<x-layouts::app :title="__('Business dashboard')">
    <main class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6">
        <header class="space-y-2">
            <flux:heading size="xl" level="1">{{ __('Your business') }}</flux:heading>
            <flux:text>{{ __('Review your business information.') }}</flux:text>
        </header>

        <section aria-label="{{ __('Business profile') }}" class="rounded-xl border border-zinc-700 bg-zinc-900 p-5">
            <flux:heading level="2">{{ $business->name }}</flux:heading>
            <flux:text>{{ __('Time zone') }}: {{ $business->timezone }}</flux:text>
            <div class="mt-5">
                <flux:button :href="route('business.edit')" wire:navigate>{{ __('Edit business profile') }}</flux:button>
            </div>
        </section>
    </main>
</x-layouts::app>
