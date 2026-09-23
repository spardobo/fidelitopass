<section class="mx-auto flex w-full max-w-2xl flex-col gap-6 px-4 py-6 sm:px-6">
    <header class="space-y-2">
        <flux:heading size="xl" level="1">{{ $this->name === '' ? __('Set up your business') : __('Business profile') }}</flux:heading>
        <flux:text>{{ __('Enter your business name and time zone to continue.') }}</flux:text>
    </header>

    <form wire:submit="save" class="flex flex-col gap-5">
        <flux:input wire:model="name" :label="__('Business name')" type="text" autocomplete="organization" required autofocus maxlength="255" />

        <flux:field>
            <flux:label>{{ __('Time zone') }}</flux:label>
            <flux:select wire:model="timezone" required>
                <flux:select.option value="">{{ __('Select a time zone') }}</flux:select.option>
                @foreach (timezone_identifiers_list() as $identifier)
                    <flux:select.option :value="$identifier">{{ $identifier }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="timezone" />
        </flux:field>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <flux:button type="submit" variant="primary">{{ __('Save business') }}</flux:button>
            @if (request()->routeIs('business.edit'))
                <flux:button :href="route('dashboard')" wire:navigate>{{ __('Back to dashboard') }}</flux:button>
            @endif
        </div>
    </form>
</section>
