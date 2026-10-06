<main class="app-theme mx-auto flex w-full max-w-5xl flex-col gap-8 px-4 py-8 sm:px-6 sm:py-12">
    @if ($editing)
        <header class="space-y-4">
            @if ($this->isDirty)
                <a href="{{ route('business.pass') }}" wire:click.prevent="cancel" wire:confirm="{{ __('business.pass.discard_confirmation') }}" class="app-button inline-flex min-h-11 items-center text-sm font-medium text-app-accent hover:underline">
                    <flux:icon.arrow-left class="me-2 size-4" />
                    {{ __('business.pass.back_to_pass') }}
                </a>
            @else
                <a href="{{ route('business.pass') }}" wire:click.prevent="cancel" class="app-button inline-flex min-h-11 items-center text-sm font-medium text-app-accent hover:underline">
                    <flux:icon.arrow-left class="me-2 size-4" />
                    {{ __('business.pass.back_to_pass') }}
                </a>
            @endif

            <div class="space-y-2">
                <p class="text-sm font-medium text-app-accent">{{ $this->business->name }} · {{ __('business.pass.business_label') }}</p>
                <flux:heading level="1" size="xl">
                    {{ __('business.pass.editor_title') }}
                </flux:heading>
                <flux:text>
                    {{ __('business.pass.editor_description') }}
                </flux:text>
            </div>
        </header>

        <section aria-label="{{ __('business.pass.editor_title') }}" class="grid gap-6 lg:grid-cols-[minmax(16rem,360px)_minmax(0,1fr)] lg:items-start">
            <div class="space-y-3">
                <flux:heading level="2" size="base">
                    {{ __('business.pass.preview_title') }}
                </flux:heading>
                <article aria-label="{{ __('business.pass.illustrative_pass') }}" class="flex aspect-[3/2] w-full max-w-[360px] flex-col gap-5 rounded-2xl p-6 shadow-2xl" style="background-color: {{ $this->previewColor }}; color: {{ $this->previewTextColor }}">
                    <div class="flex items-start justify-between gap-4">
                        <flux:heading level="2" class="text-xl! leading-7! font-bold! text-inherit!">
                            {{ $this->business->name }}
                        </flux:heading>
                        <flux:icon.credit-card class="size-8 shrink-0 opacity-80" />
                    </div>

                    <div class="grid flex-1 grid-cols-[minmax(0,1fr)_auto] items-start gap-4 border-t border-current/25 pt-5">
                        <div class="flex flex-col gap-3">
                            <flux:heading level="3" class="text-base! font-semibold! text-inherit!">
                                {{ __('business.pass.illustrative_pass') }}
                            </flux:heading>
                            <flux:text class="text-sm! text-inherit!">
                                {{ __('business.pass.illustrative_notice') }}
                            </flux:text>
                        </div>
                        <flux:icon.credit-card class="mt-1 size-10 opacity-70" />
                    </div>
                </article>
            </div>

            <div class="space-y-4">
                <form wire:submit="save" class="flex flex-col gap-5 rounded-2xl border border-app-border bg-app-surface p-5 sm:p-6">
                    <div class="flex items-start gap-3 border-b border-app-line pb-5">
                        <span aria-hidden="true" class="flex size-11 shrink-0 items-center justify-center rounded-xl border border-app-accent/40 bg-app-accent/10 text-sm font-semibold text-app-accent">{{ mb_strtoupper(mb_substr($this->business->name, 0, 1)) }}</span>
                        <div class="space-y-1">
                            <flux:heading level="2" size="base">{{ $this->business->name }}</flux:heading>
                            <flux:text>{{ __('business.pass.business_name_note') }}</flux:text>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <flux:heading level="2" size="lg">
                            {{ __('business.pass.color_heading') }}
                        </flux:heading>
                        <flux:text>
                            {{ __('business.pass.appearance_description') }}
                        </flux:text>
                    </div>

                    <fieldset class="space-y-3">
                        <legend class="text-sm font-medium text-app-ink">{{ __('business.pass.presets_label') }}</legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->colorPresets as $color)
                                <flux:button
                                    type="button"
                                    wire:click="selectColor('{{ $color }}')"
                                    aria-label="{{ __('business.pass.use_color', ['color' => $color]) }}"
                                    aria-pressed="{{ $backgroundColor === $color ? 'true' : 'false' }}"
                                    :class="$backgroundColor === $color ? 'border-app-accent ring-2 ring-app-accent' : ''"
                                    class="min-h-11 min-w-11 border border-app-border p-2 focus-visible:outline-2 focus-visible:outline-offset-3 focus-visible:outline-app-accent"
                                >
                                    <span aria-hidden="true" class="relative flex size-5 items-center justify-center rounded-full" style="background-color: {{ $color }}">
                                        @if ($backgroundColor === $color)
                                            <flux:icon.check class="size-4 text-app-on-accent drop-shadow" />
                                        @endif
                                    </span>
                                </flux:button>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="grid gap-4 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-end">
                        <div class="space-y-2">
                            <label for="pass-background-color" class="block text-sm font-medium text-app-ink">
                                {{ __('business.pass.color_picker_label') }}
                            </label>
                            <input
                                id="pass-background-color"
                                type="color"
                                wire:model.live="backgroundColor"
                                value="{{ $this->previewColor }}"
                                aria-describedby="pass-color-help"
                                class="h-11 w-16 cursor-pointer rounded-lg border border-app-border bg-app-control p-1 focus-visible:outline-2 focus-visible:outline-offset-3 focus-visible:outline-app-accent"
                            >
                        </div>

                        <flux:input
                            id="pass-background-hex"
                            wire:model.live="backgroundColor"
                            :label="__('business.pass.hex_label')"
                            type="text"
                            :value="$this->previewColor"
                            maxlength="7"
                            placeholder="#A77BFF"
                            autocomplete="off"
                            aria-describedby="pass-color-help"
                        />
                    </div>
                    <flux:description id="pass-color-help">
                        {{ __('business.pass.color_help') }}
                    </flux:description>
                    <flux:error name="backgroundColor" />

                    <div class="flex items-start gap-2 text-sm text-app-ink-help">
                        <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                        <p>{{ __('business.pass.wallet_color_note') }}</p>
                    </div>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        @if ($this->isDirty)
                            <flux:button type="button" wire:click="cancel" wire:confirm="{{ __('business.pass.discard_confirmation') }}" class="min-h-11">
                                {{ __('business.pass.cancel') }}
                            </flux:button>
                        @else
                            <flux:button type="button" wire:click="cancel" class="min-h-11">
                                {{ __('business.pass.cancel') }}
                            </flux:button>
                        @endif
                        <flux:button type="submit" variant="primary" class="app-button-primary min-h-11">
                            {{ __('business.pass.save') }}
                        </flux:button>
                    </div>
                </form>

                <flux:text>
                    {{ __('business.pass.shared_appearance_note') }}
                </flux:text>
            </div>
        </section>
    @else
        <header class="space-y-3">
            <p class="text-sm font-medium text-app-accent">{{ $this->business->name }} · {{ __('business.pass.business_label') }}</p>
            <flux:heading level="1" size="xl">
                {{ __('business.pass.overview_title', ['business' => $this->business->name]) }}
            </flux:heading>
            <flux:text>
                {{ __('business.pass.overview_description') }}
            </flux:text>
        </header>

        <section aria-labelledby="pass-overview-heading" class="grid gap-6 rounded-2xl border border-app-border bg-app-surface p-5 sm:p-7 md:grid-cols-[minmax(16rem,360px)_minmax(0,1fr)] md:items-center">
            <div class="space-y-3">
                <article aria-label="{{ __('business.pass.illustrative_pass') }}" class="flex aspect-[3/2] w-full max-w-[360px] flex-col gap-5 rounded-2xl p-6 shadow-2xl" style="background-color: {{ $this->previewColor }}; color: {{ $this->previewTextColor }}">
                    <div class="flex items-start justify-between gap-4">
                        <flux:heading level="2" class="text-xl! leading-7! font-bold! text-inherit!">
                            {{ $this->business->name }}
                        </flux:heading>
                        <flux:icon.credit-card class="size-8 shrink-0 opacity-80" />
                    </div>

                    <div class="grid flex-1 grid-cols-[minmax(0,1fr)_auto] items-start gap-4 border-t border-current/25 pt-5">
                        <div class="flex flex-col gap-3">
                            <flux:heading level="3" class="text-base! font-semibold! text-inherit!">
                                {{ __('business.pass.illustrative_pass') }}
                            </flux:heading>
                            <flux:text class="text-sm! text-inherit!">
                                {{ __('business.pass.illustrative_notice') }}
                            </flux:text>
                        </div>
                        <flux:icon.credit-card class="mt-1 size-10 opacity-70" />
                    </div>
                </article>
                @if (! $this->business->pass_background_color)
                    <div class="flex items-start gap-2 text-sm text-app-ink-help">
                        <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                        <p>{{ __('business.pass.preview_fallback') }}</p>
                    </div>
                @endif
            </div>

            <div class="space-y-4">
                @if ($this->business->pass_background_color)
                    <flux:badge color="green">
                        <flux:icon.check class="size-4" />
                        {{ __('business.pass.saved_status') }}
                    </flux:badge>
                    <div class="space-y-2">
                        <flux:heading level="2" size="lg" id="pass-overview-heading">
                            {{ __('business.pass.saved_heading') }}
                        </flux:heading>
                        <flux:text>{{ __('business.pass.saved_description', ['business' => $this->business->name]) }}</flux:text>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button href="{{ route('business.pass.appearance') }}" variant="filled" class="min-h-11">
                            {{ __('business.pass.edit_appearance') }}
                        </flux:button>
                    </div>
                    <div class="flex items-start gap-2 text-sm text-app-ink-help">
                        <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                        <p>{{ __('business.pass.saved_appearance_note') }}</p>
                    </div>
                @else
                    <flux:badge color="amber">
                        <flux:icon.information-circle class="size-4" />
                        {{ __('business.pass.unsaved_badge') }}
                    </flux:badge>
                    <div class="space-y-2">
                        <flux:heading level="2" size="lg" id="pass-overview-heading">
                            {{ __('business.pass.unsaved_heading') }}
                        </flux:heading>
                        <flux:text>{{ __('business.pass.unsaved_description') }}</flux:text>
                    </div>
                    <flux:button href="{{ route('business.pass.appearance') }}" variant="primary" class="app-button-primary min-h-11">
                        {{ __('business.pass.prepare') }}
                    </flux:button>
                    <flux:text>{{ __('business.pass.unsaved_status') }}</flux:text>
                @endif
            </div>
        </section>

        <section aria-labelledby="promotions-heading" class="space-y-3">
            <div class="space-y-1">
                <flux:heading level="2" size="lg" id="promotions-heading">
                    {{ __('business.pass.promotions_title') }}
                </flux:heading>
                <flux:text>{{ __('business.pass.promotions_description') }}</flux:text>
            </div>

            @if ($this->business->pass_background_color)
                <article class="flex flex-col gap-4 rounded-2xl border border-app-border bg-app-surface p-5 sm:flex-row sm:items-center sm:p-6">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-app-accent/40 bg-app-accent/10 text-app-accent">
                        <flux:icon.gift class="size-6" />
                    </span>
                    <div class="space-y-2">
                        <flux:heading level="3" size="base">{{ __('business.pass.promotions_empty_heading') }}</flux:heading>
                        <flux:text>{{ __('business.pass.promotions_empty_description') }}</flux:text>
                        <flux:text>{{ __('business.pass.promotions_empty') }}</flux:text>
                    </div>
                </article>
            @else
                <article class="flex flex-col gap-4 rounded-2xl border border-app-border bg-app-surface p-5 sm:flex-row sm:items-center sm:p-6">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-app-accent/40 bg-app-accent/10 text-app-accent">
                        <flux:icon.gift class="size-6" />
                    </span>
                    <div class="space-y-2">
                        <flux:heading level="3" size="base">{{ __('business.pass.promotions_prerequisite_heading') }}</flux:heading>
                        <flux:text>{{ __('business.pass.promotions_prerequisite') }}</flux:text>
                    </div>
                </article>
            @endif
        </section>
    @endif
</main>
