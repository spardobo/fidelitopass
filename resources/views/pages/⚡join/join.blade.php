<div class="flex min-h-screen flex-col bg-app-canvas text-app-ink">
    <header class="mx-auto flex w-full max-w-xl justify-center px-4 pt-8 sm:px-6 sm:pt-12">
        <a href="{{ route('home') }}" aria-label="{{ __('join.home') }}" class="inline-flex min-h-app-control cursor-pointer items-center rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-app-accent-text">
            <img src="{{ asset('logo-header.webp') }}" alt="{{ __('landing.navigation.logo_alt') }}" class="h-auto w-40 sm:w-48" width="480" height="105">
        </a>
    </header>

    <main class="mx-auto flex w-full max-w-xl flex-1 flex-col gap-8 px-4 py-8 sm:px-6 sm:py-12">
        <h1 class="sr-only">
            {{ __('join.page_title') }}
        </h1>

        <div class="flex flex-col gap-4">
            <x-interactive-pass-preview
                :show-credentials="true"
                :business-name="$businessName"
                :aria-label="__('join.card_label')"
                :background-color="$backgroundColor"
                :neutral="$backgroundColor === null"
                :reward="__('join.sample_reward')"
                :reward-description="__('join.sample_description')"
                :progress="__('join.sample_progress')"
                :extra-points="__('join.sample_extra_points')"
                :deadline="__('join.sample_deadline')"
            />
            <div class="app-note-with-icon text-app-ink-help">
                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                    <flux:icon.information-circle variant="outline" class="size-4" />
                </span>
                <p class="app-role-support!">{{ __('join.sample_caption') }}</p>
            </div>
            <p class="sr-only">{{ __('join.card_notice') }}</p>
        </div>
    </main>
</div>
