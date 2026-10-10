<section class="app-theme app-workspace">
    @include('partials.settings-heading', ['businessName' => $this->businessName])

    <x-pages::settings.layout :heading="__('business.profile_title')" :subheading="__('business.profile.description')">
        <form wire:submit="save" novalidate class="flex flex-col gap-6">
            <flux:input
                wire:model="name"
                :label="__('business.fields.business_name')"
                type="text"
                autocomplete="organization"
                required
                autofocus
                maxlength="255"
                class:input="app-input"
                label:class="app-label"
                error:class="app-error"
            />

            @php
                $timezoneChoices = \App\Support\TimezoneLabel::choices(app()->getLocale());
                $selectedTimezoneName = $timezoneChoices[$timezone]['name'] ?? '';
            @endphp

            <flux:field x-data="{ timezoneName: @js($selectedTimezoneName) }">
                <flux:label class="app-label">
                    {{ __('business.fields.time_zone') }}
                </flux:label>
                <flux:select
                    id="business-timezone"
                    wire:model="timezone"
                    x-on:change="timezoneName = $el.selectedOptions[0]?.dataset.timezoneName ?? ''"
                    aria-describedby="business-timezone-name business-timezone-guidance"
                    required
                    class="app-input"
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
                    class="app-role-support! text-app-ink-secondary!"
                    @style(['display: none' => $selectedTimezoneName === ''])
                >
                    <flux:badge x-text="timezoneName" class="max-w-full whitespace-normal! bg-app-emphasis! text-app-ink-priority!">
                        {{ $selectedTimezoneName }}
                    </flux:badge>
                </flux:description>
                <flux:error name="timezone" class="app-error" />
                <flux:description id="business-timezone-guidance" class="app-role-support! text-app-ink-help!">
                    {{ __('business.profile.timezone_guidance') }}
                </flux:description>
            </flux:field>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <flux:button :href="route('dashboard')" wire:navigate class="app-button-secondary">
                    {{ __('business.profile.back_to_dashboard') }}
                </flux:button>
                <flux:button type="submit" variant="primary" class="app-button-primary">
                    {{ __('business.profile.save') }}
                </flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
