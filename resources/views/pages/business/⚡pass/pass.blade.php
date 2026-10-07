<main class="app-theme app-workspace app-pass-page @container/pass-workspace">
    @if ($editing)
        <header class="space-y-6">
            <div class="flex min-h-11 items-center">
            @if ($this->isDirty)
                <flux:modal.trigger name="discard-pass-appearance">
                    <button type="button" class="app-button inline-flex min-h-11 items-center gap-2 text-sm font-medium text-app-accent hover:underline">
                        <flux:icon.arrow-left variant="outline" class="size-4" />
                        {{ __('business.pass.back_to_pass') }}
                    </button>
                </flux:modal.trigger>
            @else
                <a href="{{ route('business.pass') }}" wire:click.prevent="cancel" class="app-button inline-flex min-h-11 items-center gap-2 text-sm font-medium text-app-accent hover:underline">
                    <flux:icon.arrow-left variant="outline" class="size-4" />
                    {{ __('business.pass.back_to_pass') }}
                </a>
            @endif
            </div>

            <div class="space-y-2">
                <p class="app-role-body! text-app-accent">{{ $this->business->name }} · {{ __('business.pass.business_label') }}</p>
                <flux:heading level="1" size="xl" class="app-heading">
                    {{ __('business.pass.editor_title') }}
                </flux:heading>
                <flux:text size="lg" class="app-role-intro!">
                    {{ __('business.pass.editor_description') }}
                </flux:text>
            </div>
        </header>

        <section aria-label="{{ __('business.pass.editor_title') }}" class="app-pass-editor-layout grid min-w-0 gap-6">
            <div class="app-pass-preview-rail @container">
                <flux:heading level="2" size="base" class="app-role-support! font-medium! text-app-ink!">
                    {{ __('business.pass.preview_appearance_title') }}
                </flux:heading>
                <x-pass-preview
                    :business-name="$this->business->name"
                    :background-color="$this->previewColor"
                    :text-color="$this->previewTextColor"
                />
                <div class="app-note-with-icon text-app-ink-help">
                    <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                        <flux:icon.information-circle variant="outline" class="size-4" />
                    </span>
                    @if (! $this->business->pass_background_color)
                        <p class="app-role-support!">{{ __('business.pass.preview_unsaved_caption') }}</p>
                    @else
                        <p class="app-role-support!">{{ __('business.pass.preview_saved_caption') }}</p>
                    @endif
                </div>
            </div>

            <div class="min-w-0 space-y-4">
                <form wire:submit="save" class="@container/pass-appearance-form flex flex-col gap-5 rounded-2xl border border-app-border bg-app-surface p-5 sm:p-6">
                    <div class="flex items-start gap-3 border-b border-app-line pb-5">
                        <span aria-hidden="true" class="flex size-11 shrink-0 items-center justify-center rounded-xl border border-app-accent/40 bg-app-accent/10 text-sm font-semibold text-app-accent">{{ mb_strtoupper(mb_substr($this->business->name, 0, 1)) }}</span>
                        <div class="space-y-1">
                            <flux:heading level="2" size="base" class="app-role-card! text-app-ink!">{{ $this->business->name }}</flux:heading>
                            <flux:text size="base" class="app-role-support!">{{ __('business.pass.business_name_note') }}</flux:text>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <flux:heading level="2" size="lg" class="app-role-section! text-app-ink!">
                            {{ __('business.pass.color_heading') }}
                        </flux:heading>
                        <flux:text size="base" class="app-role-support!">
                            {{ __('business.pass.appearance_description') }}
                        </flux:text>
                    </div>

                    <fieldset class="app-pass-color-control-row flex min-w-0 flex-wrap items-end gap-2">
                        <legend class="sr-only">{{ __('business.pass.presets_label') }}</legend>
                        <div class="app-pass-color-preset-list flex min-w-0 flex-wrap gap-1">
                            @foreach ($this->colorPresets as $index => $color)
                                <flux:button
                                    type="button"
                                    square
                                    variant="ghost"
                                    size="sm"
                                    wire:click="selectColor('{{ $color }}')"
                                    aria-label="{{ __('business.pass.use_color', ['color' => $this->colorPresetNames[$index], 'hex' => $color]) }}"
                                    aria-pressed="{{ $backgroundColor === $color ? 'true' : 'false' }}"
                                    :class="$backgroundColor === $color ? 'outline outline-2 outline-offset-2 outline-app-accent' : ''"
                                    class="app-pass-color-preset shrink-0 p-1 focus-visible:outline-2 focus-visible:outline-offset-3 focus-visible:outline-app-accent"
                                >
                                    <span aria-hidden="true" class="app-pass-color-swatch relative flex size-8 items-center justify-center rounded-lg" style="background-color: {{ $color }}; color: {{ $this->previewTextColor }}">
                                        @if ($backgroundColor === $color)
                                            <flux:icon.check variant="outline" class="size-4 text-inherit drop-shadow" />
                                        @endif
                                    </span>
                                </flux:button>
                            @endforeach
                        </div>

                        <label for="pass-background-color" class="sr-only">
                            {{ __('business.pass.color_picker_label') }}
                        </label>
                        <input
                            id="pass-background-color"
                            type="color"
                            wire:model.live="backgroundColor"
                            value="{{ $this->previewColor }}"
                            aria-describedby="pass-color-error"
                            class="size-11 shrink-0 cursor-pointer rounded-lg border border-app-border bg-transparent p-1"
                        >

                        <flux:field class="w-28 shrink-0">
                            <flux:label for="pass-background-hex" class="sr-only">
                                {{ __('business.pass.hex_label') }}
                            </flux:label>
                            <flux:input
                                id="pass-background-hex"
                                wire:model.live="backgroundColor"
                                type="text"
                                :value="$this->previewColor"
                                maxlength="7"
                                placeholder="#A77BFF"
                                autocomplete="off"
                                input:class="app-role-body!"
                                aria-describedby="pass-color-error"
                            />
                        </flux:field>
                    </fieldset>
                    <flux:error id="pass-color-error" name="backgroundColor" />

                    <div class="app-note-with-icon text-app-ink-help">
                        <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                            <flux:icon.information-circle variant="outline" class="size-4" />
                        </span>
                        <p class="app-role-support!">{{ __('business.pass.wallet_color_note') }}</p>
                    </div>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        @if ($this->isDirty)
                            <flux:modal.trigger name="discard-pass-appearance">
                                <flux:button type="button" class="app-role-action! min-h-11">
                                    {{ __('business.pass.cancel') }}
                                </flux:button>
                            </flux:modal.trigger>
                        @else
                            <flux:button type="button" wire:click="cancel" class="app-role-action! min-h-11">
                                {{ __('business.pass.cancel') }}
                            </flux:button>
                        @endif
                        <flux:button type="submit" variant="primary" class="app-button-primary min-h-11">
                            {{ __('business.pass.save') }}
                        </flux:button>
                    </div>
                </form>

                <flux:modal name="discard-pass-appearance" class="app-theme space-y-6">
                    <div class="space-y-2">
                        <flux:heading size="lg" class="app-role-section! text-app-ink!">{{ __('business.pass.discard_title') }}</flux:heading>
                        <flux:text size="base" class="app-role-support!">{{ __('business.pass.discard_confirmation') }}</flux:text>
                    </div>
                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <flux:modal.close class="w-full sm:w-auto">
                            <flux:button type="button" class="app-role-action! min-h-11 w-full sm:w-auto">{{ __('business.pass.continue_editing') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="button" wire:click="cancel" variant="primary" class="app-button-primary min-h-11 w-full sm:w-auto">
                            {{ __('business.pass.discard_changes') }}
                        </flux:button>
                    </div>
                </flux:modal>
            </div>
        </section>


    @else
        <header class="space-y-2">
            <p class="app-role-body! text-app-accent">{{ $this->business->name }} · {{ __('business.pass.business_label') }}</p>
        <flux:heading level="1" size="xl" class="app-heading">
                {{ __('business.pass.overview_title', ['business' => $this->business->name]) }}
            </flux:heading>
            <flux:text size="lg" class="app-role-intro!">
                {{ __('business.pass.overview_description') }}
            </flux:text>
        </header>

        <section aria-labelledby="pass-overview-heading" class="app-pass-overview-layout grid min-w-0 gap-6 rounded-2xl border border-app-border bg-app-surface p-4 sm:p-6">
            <div class="app-pass-preview-rail @container">
                <x-pass-preview
                    :business-name="$this->business->name"
                    :background-color="$this->previewColor"
                    :text-color="$this->previewTextColor"
                />
                <div class="app-note-with-icon text-app-ink-help">
                    <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                        <flux:icon.information-circle variant="outline" class="size-4" />
                    </span>
                    <p class="app-role-support!">
                        @if (! $this->business->pass_background_color)
                            {{ __('business.pass.preview_unsaved_caption') }}
                        @else
                            {{ __('business.pass.preview_saved_caption') }}
                        @endif
                    </p>
                </div>
            </div>

            <div class="app-pass-overview-content min-w-0 space-y-4">
                @if ($this->business->pass_background_color)
                    <flux:badge icon="check" color="green" class="app-role-support! font-medium!">
                        {{ __('business.pass.saved_status') }}
                    </flux:badge>
                    <div class="space-y-2">
                        <flux:heading level="2" size="lg" id="pass-overview-heading" class="app-role-section! text-app-ink!">
                            {{ __('business.pass.saved_heading') }}
                        </flux:heading>
                        <flux:text size="lg" class="app-role-body!">{{ __('business.pass.saved_description', ['business' => $this->business->name]) }}</flux:text>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button href="{{ route('business.pass.appearance') }}" variant="filled" class="app-role-action! min-h-11">
                            {{ __('business.pass.edit_appearance') }}
                        </flux:button>
                        <flux:button href="{{ url('/pass/invite') }}" variant="ghost" class="app-role-action! min-h-11">
                            {{ __('business.pass.invite_customers') }}
                            <flux:icon.arrow-right variant="outline" class="ms-2 size-4" />
                        </flux:button>
                    </div>
                    <div class="app-note-with-icon text-app-ink-help">
                        <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                            <flux:icon.information-circle variant="outline" class="size-4" />
                        </span>
                        <p class="app-role-support!">{{ __('business.pass.saved_appearance_note') }}</p>
                    </div>
                @else
                    <flux:badge color="amber" class="app-role-support! font-medium!">
                        {{ __('business.pass.unsaved_badge') }}
                    </flux:badge>
                    <div class="space-y-2">
                        <flux:heading level="2" size="lg" id="pass-overview-heading" class="app-role-section! text-app-ink!">
                            {{ __('business.pass.unsaved_heading') }}
                        </flux:heading>
                        <flux:text size="lg" class="app-role-body!">{{ __('business.pass.unsaved_description') }}</flux:text>
                    </div>
                    <flux:button href="{{ route('business.pass.appearance') }}" variant="primary" class="app-button-primary min-h-11">
                        {{ __('business.pass.prepare') }}
                    </flux:button>
                @endif
            </div>
        </section>

        <section aria-labelledby="promotions-heading" class="space-y-4">
            <div class="space-y-1">
                <flux:heading level="2" size="lg" id="promotions-heading" class="app-role-section! text-app-ink!">
                    {{ __('business.pass.promotions_title') }}
                </flux:heading>
                <div class="app-note-with-icon text-app-ink-help">
                    <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                        <flux:icon.information-circle variant="outline" class="size-4" />
                    </span>
                    <flux:text size="base" class="app-role-support! text-app-ink-help!">{{ __('business.pass.promotions_description') }}</flux:text>
                </div>
            </div>

            @if ($this->business->pass_background_color)
                <article class="flex flex-col gap-4 rounded-2xl border border-app-border bg-app-surface p-4 sm:flex-row sm:items-center sm:p-6">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-app-accent/40 bg-app-accent/10 text-app-accent">
                        <flux:icon.gift variant="outline" class="size-6" />
                    </span>
                    <div class="flex min-w-0 flex-col gap-6">
                        <div class="space-y-2">
                            <flux:heading level="3" size="base" class="app-role-card! text-app-ink!">{{ __('business.pass.promotions_prerequisite_heading') }}</flux:heading>
                            <flux:text size="lg" class="app-role-body!">{{ __('business.pass.create_promotion_description') }}</flux:text>
                        </div>
                        <flux:button href="{{ url('/promotions/create') }}" variant="primary" class="app-button-primary min-h-11 w-full self-start sm:w-auto">
                            <flux:icon.plus variant="outline" class="me-2 size-4" />
                            {{ __('business.pass.create_first_promotion') }}
                        </flux:button>
                    </div>
                </article>
            @else
                <article class="flex flex-col gap-4 rounded-2xl border border-app-border bg-app-surface p-4 sm:flex-row sm:items-center sm:p-6">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-app-accent/40 bg-app-accent/10 text-app-accent">
                        <flux:icon.gift variant="outline" class="size-6" />
                    </span>
                    <div class="space-y-2">
                        <flux:heading level="3" size="base" class="app-role-card! text-app-ink!">{{ __('business.pass.promotions_empty_heading') }}</flux:heading>
                        <flux:text size="lg" class="app-role-body!">{{ __('business.pass.promotions_empty_description') }}</flux:text>
                        <flux:text size="base" class="app-role-support!">{{ __('business.pass.promotions_prerequisite') }}</flux:text>
                    </div>
                </article>
            @endif
        </section>
    @endif
</main>
