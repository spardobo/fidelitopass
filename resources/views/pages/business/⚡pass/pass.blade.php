<main class="app-theme app-workspace app-pass-page @container/pass-workspace">
    @if ($draftSavedNoticePending)
        <span
            class="hidden"
            x-data
            x-init="$nextTick(() => requestAnimationFrame(() => $flux.toast({ text: @js(__('business.promotion.draft_saved')), variant: 'success' })))"
        ></span>
    @endif

    @if ($publicationNotice !== '')
        <span
            class="hidden"
            x-data
            x-init="$nextTick(() => requestAnimationFrame(() => $flux.toast({ text: @js(__('business.promotion.publication_notice_'.$publicationNotice)), variant: 'success' })))"
        ></span>
    @endif

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
                    <flux:error id="pass-color-error" name="backgroundColor" class="app-error" />

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

        @php
            $promotionListings = $this->currentPromotionListings;
            $hasPublishedPromotions = $promotionListings['active'] !== null || $promotionListings['scheduled']->total() > 0;
            $hasPromotionRows = $hasPublishedPromotions || $this->draftPromotions->total() > 0 || $promotionListings['history']->total() > 0;
        @endphp

        <section aria-labelledby="promotions-heading" class="space-y-4">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="space-y-1">
                    <flux:heading level="2" size="lg" id="promotions-heading" tabindex="-1" class="app-role-section! text-app-ink!">
                        {{ __('business.pass.promotions_title') }}
                    </flux:heading>
                    <div class="app-note-with-icon text-app-ink-help">
                        <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                            <flux:icon.information-circle variant="outline" class="size-4" />
                        </span>
                        <flux:text size="base" class="app-role-support! text-app-ink-help!">{{ __('business.pass.promotions_description') }}</flux:text>
                    </div>
                </div>

                @if ($hasPromotionRows && $this->business->pass_background_color)
                    <flux:button href="{{ route('business.promotions.create') }}" variant="primary" class="app-button-primary min-h-11 w-full shrink-0 sm:w-auto">
                        <flux:icon.plus variant="outline" class="me-2 size-4" />
                        {{ __('business.pass.new_promotion') }}
                    </flux:button>
                @endif
            </div>

            @if (! $hasPromotionRows && $this->business->pass_background_color)
                <article class="flex flex-col gap-4 rounded-2xl border border-app-border bg-app-surface p-4 sm:flex-row sm:items-center sm:p-6">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-app-accent/40 bg-app-accent/10 text-app-accent">
                        <flux:icon.gift variant="outline" class="size-6" />
                    </span>
                    <div class="flex min-w-0 flex-col gap-6">
                        <div class="space-y-2">
                            <flux:heading level="3" size="base" class="app-role-card! text-app-ink!">{{ __('business.pass.promotions_prerequisite_heading') }}</flux:heading>
                            <flux:text size="lg" class="app-role-body!">{{ __('business.pass.create_promotion_description') }}</flux:text>
                        </div>
                        <flux:button href="{{ route('business.promotions.create') }}" variant="primary" class="app-button-primary min-h-11 w-full self-start sm:w-auto">
                            <flux:icon.plus variant="outline" class="me-2 size-4" />
                            {{ __('business.pass.create_first_promotion') }}
                        </flux:button>
                    </div>
                </article>
            @elseif (! $hasPromotionRows)
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
            @elseif (! $this->business->pass_background_color)
                <div class="app-note-with-icon text-app-ink-help">
                    <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                        <flux:icon.information-circle variant="outline" class="size-4" />
                    </span>
                    <flux:text size="base" class="app-role-support! text-app-ink-help!">{{ __('business.pass.promotions_prerequisite') }}</flux:text>
                </div>
            @endif

            @if ($promotionListings['active'] !== null)
                @php($activePromotion = $promotionListings['active'])
                <div class="space-y-3">
                    <flux:heading level="3" size="base" class="app-role-card! text-app-ink!">
                        {{ __('business.pass.active_promotion_heading') }}
                    </flux:heading>
                    <article
                        data-promotion-phase="active"
                        data-promotion-public-id="{{ $activePromotion['promotion']->public_id }}"
                        class="min-w-0 space-y-3 rounded-2xl border border-app-accent/60 bg-app-surface p-4 sm:p-6"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 space-y-1">
                                <flux:heading level="4" size="base" class="app-role-card! text-app-ink!">
                                    {{ $activePromotion['promotion']->reward_title }}
                                </flux:heading>
                                @if ($activePromotion['promotion']->reward_description !== '')
                                    <flux:text class="app-role-support! text-app-ink-secondary!">
                                        {{ $activePromotion['promotion']->reward_description }}
                                    </flux:text>
                                @endif
                            </div>
                            <flux:badge color="green" class="app-role-support! font-medium!">
                                {{ __('business.pass.promotion_active_status') }}
                            </flux:badge>
                        </div>
                        <flux:text class="app-role-support! text-app-ink-secondary!">
                            {{ $activePromotion['period'] }}
                            ·
                            {{ __('business.pass.promotion_published_target', ['points' => $activePromotion['promotion']->target_points]) }}
                        </flux:text>
                        <flux:button
                            id="promotion-detail-trigger-{{ $activePromotion['promotion']->public_id }}"
                            type="button"
                            wire:click="showPromotionDetail('{{ $activePromotion['promotion']->public_id }}')"
                            wire:loading.attr="disabled"
                            wire:target="showPromotionDetail"
                            variant="filled"
                            class="app-button-secondary min-h-11 w-full sm:w-auto"
                        >
                            {{ __('business.pass.view_promotion_detail') }}
                        </flux:button>
                    </article>
                </div>
            @endif

            @if ($promotionListings['scheduled']->total() > 0)
                <details wire:key="scheduled-promotions-disclosure" wire:ignore.self open class="group space-y-3">
                    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-lg py-2 app-focus">
                        <span role="heading" aria-level="3" class="app-role-card! text-app-ink!">
                            {{ __('business.pass.scheduled_promotions_heading', ['count' => $promotionListings['scheduled']->total()]) }}
                        </span>
                        <flux:icon.chevron-down variant="outline" class="size-5 shrink-0 text-app-ink-secondary transition-transform group-open:rotate-180 motion-reduce:transition-none" />
                    </summary>
                    <ul class="space-y-3" aria-label="{{ __('business.pass.scheduled_promotions_heading', ['count' => $promotionListings['scheduled']->total()]) }}">
                        @foreach ($promotionListings['scheduled'] as $scheduledPromotion)
                            <li
                                wire:key="promotion-scheduled-{{ $scheduledPromotion['promotion']->public_id }}"
                                data-promotion-phase="scheduled"
                                data-promotion-public-id="{{ $scheduledPromotion['promotion']->public_id }}"
                                class="flex min-w-0 flex-col gap-3 rounded-2xl border border-app-border bg-app-surface p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6"
                            >
                                <div class="min-w-0 space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:heading level="4" size="base" class="app-role-card! text-app-ink!">
                                            {{ $scheduledPromotion['promotion']->reward_title }}
                                        </flux:heading>
                                        <flux:badge color="blue" class="app-role-support! font-medium!">
                                            {{ __('business.pass.promotion_scheduled_status') }}
                                        </flux:badge>
                                    </div>
                                    @if ($scheduledPromotion['promotion']->reward_description !== '')
                                        <flux:text class="app-role-support! text-app-ink-secondary!">
                                            {{ $scheduledPromotion['promotion']->reward_description }}
                                        </flux:text>
                                    @endif
                                    <flux:text class="app-role-support! text-app-ink-secondary!">
                                        {{ $scheduledPromotion['period'] }}
                                        ·
                                        {{ __('business.pass.promotion_published_target', ['points' => $scheduledPromotion['promotion']->target_points]) }}
                                    </flux:text>
                                </div>
                                <flux:button
                                    id="promotion-detail-trigger-{{ $scheduledPromotion['promotion']->public_id }}"
                                    type="button"
                                    wire:click="showPromotionDetail('{{ $scheduledPromotion['promotion']->public_id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="showPromotionDetail"
                                    variant="filled"
                                    class="app-button-secondary min-h-11 w-full sm:w-auto"
                                >
                                    {{ __('business.pass.view_promotion_detail') }}
                                </flux:button>
                            </li>
                        @endforeach
                    </ul>
                    @if ($promotionListings['scheduled']->hasPages())
                        <flux:pagination :paginator="$promotionListings['scheduled']" />
                    @endif
                </details>
            @endif

            @if ($this->draftPromotions->total() > 0)
                <details wire:key="promotion-drafts-disclosure" wire:ignore.self open class="group space-y-3">
                    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-lg py-2 app-focus">
                        <span role="heading" aria-level="3" class="app-role-card! text-app-ink!">
                            {{ __('business.pass.drafts_heading', ['count' => $this->draftPromotions->total()]) }}
                        </span>
                        <flux:icon.chevron-down variant="outline" class="size-5 shrink-0 text-app-ink-secondary transition-transform group-open:rotate-180 motion-reduce:transition-none" />
                    </summary>
                    @if ($this->draftPromotions->isNotEmpty())
                        <ul class="space-y-4" aria-label="{{ __('business.pass.saved_promotion_drafts') }}">
                            @foreach ($this->draftPromotions as $promotion)
                                <li
                                    wire:key="promotion-draft-{{ $promotion->public_id }}"
                                    data-promotion-phase="draft"
                                    data-promotion-public-id="{{ $promotion->public_id }}"
                                    class="flex min-w-0 flex-col gap-3 rounded-2xl border border-app-border bg-app-surface p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6"
                                >
                                    <div class="min-w-0 space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <flux:heading level="3" size="base" class="app-role-card! text-app-ink!">
                                                {{ $promotion->reward_title }}
                                            </flux:heading>
                                            <flux:badge color="amber" class="app-role-support! font-medium!">
                                                {{ __('business.pass.promotion_draft_status') }}
                                            </flux:badge>
                                        </div>
                                        <flux:text class="app-role-support! text-app-ink-secondary!">
                                            {{ __('business.pass.promotion_draft_period', [
                                                'start' => $promotion->local_start_date->format('d/m/Y'),
                                                'end' => $promotion->local_end_date->format('d/m/Y'),
                                            ]) }}
                                            ·
                                            {{ __('business.pass.promotion_draft_target', ['points' => $promotion->target_points]) }}
                                        </flux:text>
                                    </div>
                                    <flux:button
                                        href="{{ route('business.promotions.edit', ['promotion' => $promotion->public_id]) }}"
                                        variant="filled"
                                        class="app-button-secondary min-h-11 w-full shrink-0 sm:w-auto"
                                    >
                                        {{ __('business.pass.edit_promotion_draft') }}
                                    </flux:button>
                                </li>
                            @endforeach
                        </ul>

                    @else
                        <p class="app-role-support! text-app-ink-secondary!">
                            {{ __('business.pass.promotions_empty') }}
                        </p>
                    @endif
                    @if ($this->draftPromotions->hasPages())
                        <flux:pagination :paginator="$this->draftPromotions" />
                    @endif
                </details>
            @elseif (! $hasPromotionRows)
                <p class="app-role-support! text-app-ink-secondary!">
                    {{ __('business.pass.promotions_empty') }}
                </p>
            @endif

            @if ($promotionListings['history']->total() > 0)
                <details wire:key="promotion-history-disclosure" wire:ignore.self class="group space-y-3">
                    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-lg py-2 app-focus">
                        <span role="heading" aria-level="3" class="app-role-card! text-app-ink!">
                            {{ __('business.pass.history_promotions_heading', ['count' => $promotionListings['history']->total()]) }}
                        </span>
                        <flux:icon.chevron-down variant="outline" class="size-5 shrink-0 text-app-ink-secondary transition-transform group-open:rotate-180 motion-reduce:transition-none" />
                    </summary>
                    <ul aria-label="{{ __('business.pass.history_promotions_heading', ['count' => $promotionListings['history']->total()]) }}" class="divide-y divide-app-line">
                        @foreach ($promotionListings['history'] as $historicalPromotion)
                            <li
                                wire:key="promotion-history-{{ $historicalPromotion['promotion']->public_id }}"
                                data-promotion-phase="{{ $historicalPromotion['phase'] }}"
                                data-promotion-public-id="{{ $historicalPromotion['promotion']->public_id }}"
                                class="flex min-w-0 flex-col gap-2 py-2 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="min-w-0 space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:heading level="4" size="base" class="app-role-support! min-w-0 break-words font-medium! text-app-ink!">
                                            {{ $historicalPromotion['promotion']->reward_title }}
                                        </flux:heading>
                                        <flux:badge size="sm">
                                            {{ __('business.pass.promotion_'.$historicalPromotion['phase'].'_status') }}
                                        </flux:badge>
                                    </div>
                                    <flux:text class="app-role-support! text-app-ink-secondary!">
                                        {{ $historicalPromotion['period'] }}
                                    </flux:text>
                                </div>
                                <flux:button
                                    id="promotion-detail-trigger-{{ $historicalPromotion['promotion']->public_id }}"
                                    type="button"
                                    wire:click="showPromotionDetail('{{ $historicalPromotion['promotion']->public_id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="showPromotionDetail"
                                    variant="ghost"
                                    class="app-control! app-role-support! app-focus! shrink-0 self-start px-0! font-medium! text-app-accent-text! hover:bg-transparent! hover:underline focus-visible:underline underline-offset-4 sm:self-center"
                                >
                                    {{ __('business.pass.view_promotion_detail') }}
                                </flux:button>
                            </li>
                        @endforeach
                    </ul>
                    @if ($promotionListings['history']->hasPages())
                        <flux:pagination :paginator="$promotionListings['history']" />
                    @endif
                </details>
            @endif
        </section>
    @endif

    <flux:modal
        name="promotion-detail"
        scroll="body"
        x-on:promotion-cancellation-focus.window="$nextTick(() => requestAnimationFrame(() => $el.querySelector('#' + $event.detail.target)?.focus()))"
        x-on:close="
            const originId = 'promotion-detail-trigger-' + $wire.selectedPromotionId;
            $wire.dismissPromotionDetail().then(() => {
                const root = $el.closest('main');
                const origin = root.querySelector('#' + originId);
                const canFocusOrigin = origin && !origin.closest('details:not([open])') && origin.getClientRects().length > 0;
                const focusTarget = canFocusOrigin ? origin : root.querySelector('#promotions-heading');
                focusTarget?.focus({ preventScroll: true });
            });
        "
        class="app-theme w-[calc(100vw-2rem)] sm:w-[calc(100vw-3rem)] max-w-xl! min-w-0! max-h-[calc(100dvh-2rem)]! sm:max-h-[calc(100dvh-3rem)]! flex flex-col overflow-hidden!"
    >
        @if ($detail = $this->promotionDetail)
            <div data-promotion-detail-phase="{{ $detail['phase'] }}" class="flex min-h-0 flex-1 flex-col">
                <header class="flex shrink-0 min-w-0 items-center gap-4 pb-2 pe-10">
                    <span class="flex size-16 shrink-0 items-center justify-center rounded-full bg-app-emphasis text-app-accent" aria-hidden="true">
                        <flux:icon.document-text variant="outline" class="size-9" />
                    </span>
                    <div class="min-w-0 space-y-1">
                        <flux:badge :color="match ($detail['phase']) { 'active' => 'green', 'scheduled' => 'blue', default => null }" class="app-role-support! font-medium!">
                            {{ __('business.pass.promotion_'.$detail['phase'].'_status') }}
                        </flux:badge>
                        <flux:heading level="2" id="promotion-detail-heading" tabindex="-1" class="app-role-title! break-words text-app-ink!">
                            {{ __('business.pass.promotion_detail_title') }}
                        </flux:heading>
                        <flux:text class="app-role-support! break-words text-app-ink-secondary!">
                            {{ __('business.pass.promotion_detail_description') }}
                        </flux:text>
                    </div>
                </header>

                <div data-test="promotion-detail-scroll-body" tabindex="0" autofocus class="min-h-0 flex-1 overflow-y-auto py-3">
                    <x-promotion-summary
                        :reward-title="$detail['promotion']->reward_title"
                        :reward-description="$detail['promotion']->reward_description"
                        :target-points="$detail['promotion']->target_points"
                        :start-date="$detail['start_date']"
                        :end-date="$detail['end_date']"
                        :extra-points="$detail['extra_points']"
                    />

                    @error('promotionCancellation')
                        <p id="promotion-cancellation-error" tabindex="-1" role="alert" class="mt-4 app-error">
                            {{ $message }}
                        </p>
                    @enderror

                    @if ($confirmingPromotionCancellation && in_array($detail['phase'], ['active', 'scheduled'], true))
                        <flux:callout variant="danger" icon="exclamation-triangle" aria-labelledby="promotion-cancellation-heading" role="region" class="mt-4">
                            <flux:heading level="3" id="promotion-cancellation-heading" tabindex="-1" class="app-role-card! text-app-ink!">
                                {{ __('business.pass.cancel_promotion_heading') }}
                            </flux:heading>
                            <flux:text class="app-role-support! text-app-ink-secondary!">
                                {{ __('business.pass.cancel_promotion_warning') }}
                            </flux:text>
                        </flux:callout>
                    @endif
                </div>

                <footer class="flex shrink-0 flex-col-reverse justify-end gap-3 pt-3 sm:flex-row">
                    <flux:modal.close class="w-full sm:w-auto">
                        <flux:button type="button" class="app-button-secondary min-h-11 w-full sm:w-auto">
                            {{ __('business.pass.close_promotion_detail') }}
                        </flux:button>
                    </flux:modal.close>
                    @if ($confirmingPromotionCancellation && in_array($detail['phase'], ['active', 'scheduled'], true))
                        <flux:button type="button" wire:click="confirmPromotionCancellation" variant="danger" class="app-button min-h-11 w-full sm:w-auto">
                            {{ __('business.pass.confirm_cancel_promotion') }}
                        </flux:button>
                    @elseif (in_array($detail['phase'], ['active', 'scheduled'], true))
                        <flux:button type="button" id="cancel-promotion" wire:click="requestPromotionCancellation" variant="primary" class="app-button-primary min-h-11 w-full sm:w-auto">
                            {{ __('business.pass.cancel_promotion') }}
                        </flux:button>
                    @endif
                </footer>
            </div>
        @endif
    </flux:modal>
</main>
