<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('appearance.dark_mode_heading')">
        <flux:text>
            {{ __('appearance.dark_mode_description') }}
        </flux:text>
    </x-pages::settings.layout>
</section>
