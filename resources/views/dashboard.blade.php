<x-layouts::app :title="__('business.dashboard.page_title')">
    <main class="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-10 sm:px-6 sm:py-14">
        <header class="space-y-3">
            <flux:heading size="xl" level="1" class="text-[#f6f5f2]">
                {{ __('business.dashboard.heading') }}
            </flux:heading>
            <flux:text class="text-[#bdc1bc]">
                {{ __('business.dashboard.description') }}
            </flux:text>
        </header>

        <section aria-label="{{ __('business.profile_title') }}" class="rounded-2xl border border-[#414141] bg-[#272727] p-6 sm:p-8">
            <div class="space-y-2">
                <flux:heading level="2" class="text-[#f6f5f2]">
                    {{ $business->name }}
                </flux:heading>
                <flux:text class="text-[#bdc1bc]">
                    {{ __('business.onboarding.time_zone') }}: {{ $business->timezone }}
                </flux:text>
            </div>
            <div class="mt-6">
                <flux:button :href="route('business.edit')" wire:navigate class="border-[#6d647c] text-[#e3d7ff]">
                    {{ __('business.dashboard.edit_profile') }}
                </flux:button>
            </div>
        </section>
    </main>
</x-layouts::app>
