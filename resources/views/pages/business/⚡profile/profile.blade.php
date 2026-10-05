<section class="mx-auto flex w-full max-w-2xl flex-col gap-8 px-4 py-10 sm:px-6 sm:py-14">
    <header class="space-y-3">
        <flux:heading size="xl" level="1" class="text-[#f6f5f2]">
            {{ __('business.profile_title') }}
        </flux:heading>
        <flux:text class="text-[#bdc1bc]">
            {{ __('business.profile.description') }}
        </flux:text>
    </header>

    <form wire:submit="save" class="flex flex-col gap-6 rounded-2xl border border-[#414141] bg-[#272727] p-5 sm:p-8">
        <flux:input wire:model="name" :label="__('business.fields.business_name')" type="text" autocomplete="organization" required autofocus maxlength="255" />

        @php
            $timezoneChoices = \App\Support\TimezoneLabel::choices(app()->getLocale());
            $selectedTimezoneName = $timezoneChoices[$timezone]['name'] ?? '';
        @endphp

        <flux:field x-data="{ timezoneName: @js($selectedTimezoneName) }">
            <flux:label>
                {{ __('business.fields.time_zone') }}
            </flux:label>
            <flux:select
                id="business-timezone"
                wire:model="timezone"
                x-on:change="timezoneName = $el.selectedOptions[0]?.dataset.timezoneName ?? ''"
                aria-describedby="business-timezone-name business-timezone-guidance"
                required
            >
                <flux:select.option value="">
                    {{ __('business.fields.select_time_zone') }}
                </flux:select.option>
                @foreach ($timezoneChoices as $identifier => $choice)
                    <flux:select.option :value="$identifier" wire:key="timezone-{{ $identifier }}" :data-timezone-name="$choice['name']">
                        {{ $choice['label'] }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:description
                id="business-timezone-name"
                x-show="timezoneName !== ''"
                aria-live="polite"
                @style(['display: none' => $selectedTimezoneName === ''])
            >
                <flux:badge x-text="timezoneName" class="max-w-full whitespace-normal! bg-app-emphasis! text-app-ink-priority!">
                    {{ $selectedTimezoneName }}
                </flux:badge>
            </flux:description>
            <flux:error name="timezone" />
            <flux:description id="business-timezone-guidance">
                {{ __('business.profile.timezone_guidance') }}
            </flux:description>
        </flux:field>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <flux:button type="submit" variant="primary" class="bg-[#b7abe4] text-[#181818] hover:bg-[#c9bdf0]">
                {{ __('business.profile.save') }}
            </flux:button>
            <flux:button :href="route('dashboard')" wire:navigate class="border-[#6d647c] text-[#e3d7ff]">
                {{ __('business.profile.back_to_dashboard') }}
            </flux:button>
        </div>
    </form>
</section>
