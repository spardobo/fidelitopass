<?php

use Livewire\Component;

new class extends Component {}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading class="app-role-section! text-app-ink!">
            {{ __('Delete account') }}
        </flux:heading>
        <flux:subheading class="app-note-with-icon app-description text-app-danger-ink!">
            <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                <flux:icon.exclamation-triangle variant="outline" class="size-4" />
            </span>
            <span>
                {{ __('Delete your account and all of its resources') }}
            </span>
        </flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button
            variant="danger"
            data-test="delete-user-button"
            class="app-button"
        >
            {{ __('Delete account') }}
        </flux:button>
    </flux:modal.trigger>

    <livewire:pages::settings.delete-user-modal />
</section>
