@props([
    'businessName',
    'ariaLabel' => null,
    'backgroundColor' => null,
    'textColor' => null,
    'marketing' => false,
])

<article
    aria-label="{{ $ariaLabel ?? __('business.pass.illustrative_pass') }}"
    {{ $attributes->class([
        'app-pass-preview',
        'app-pass-preview--marketing' => $marketing,
        'app-pass-preview--with-qr' => isset($qr),
        'app-pass-preview--with-manual-code' => isset($manualCode),
    ]) }}
    @if ($backgroundColor && $textColor)
        style="background-color: {{ $backgroundColor }}; color: {{ $textColor }}"
    @endif
>
    <div class="app-pass-preview-header items-center!">
        <flux:heading level="2" class="{{ $marketing ? 'app-role-marketing-pass-brand' : 'app-role-pass-preview-brand' }} min-w-0 break-words text-inherit!">
            {{ $businessName }}
        </flux:heading>

        <x-pass-brand-mark />
    </div>

    <div class="app-pass-preview-content">
        <div class="app-pass-preview-copy">
            <div class="app-pass-preview-details">
                <div class="flex items-center gap-1 font-semibold uppercase tracking-wide text-inherit">
                    <flux:icon.tag variant="outline" class="app-pass-preview-promotion-icon size-4" />
                    <flux:heading level="3" class="{{ $marketing ? 'app-role-marketing-pass-label' : 'app-role-pass-preview-label' }} text-inherit!">
                        {{ $promotionLabel ?? __('business.pass.color_sample_label') }}
                    </flux:heading>
                </div>

                <flux:text class="{{ $marketing ? 'app-role-marketing-pass-detail' : 'app-role-pass-preview-compact' }} text-inherit!">
                    {{ $promotionDescription ?? __('business.pass.color_sample_description') }}
                </flux:text>
            </div>

            <flux:text class="{{ $marketing ? 'app-role-marketing-pass-metric' : 'app-role-pass-preview-metric' }} text-inherit!">
                {{ $progress ?? __('business.pass.color_sample_progress') }}
            </flux:text>

            <div class="flex min-w-0 items-start gap-1 text-inherit">
                <span class="inline-flex h-4 w-3 shrink-0 items-center justify-center">
                    <flux:icon.bolt variant="outline" class="size-3" />
                </span>
                <flux:text class="{{ $marketing ? 'app-role-marketing-pass-detail' : 'app-role-pass-preview-compact' }} text-inherit!">
                    {{ $extraPoints ?? __('business.pass.color_sample_extra_points') }}
                </flux:text>
            </div>
        </div>

        @if (isset($qr))
            <aside class="app-pass-preview-qr">
                {{ $qr }}
            </aside>
        @endif
    </div>

    <div class="app-pass-preview-reward">
        <div class="flex min-w-0 flex-col justify-center gap-1">
            <div class="flex min-w-0 items-start gap-1 font-semibold">
                <span class="inline-flex h-4 w-3 shrink-0 items-center justify-center">
                    <flux:icon.gift variant="outline" class="size-3" />
                </span>
                <flux:text class="{{ $marketing ? 'app-role-marketing-pass-reward' : 'app-role-pass-preview-reward' }} text-inherit!">
                    {{ $reward ?? __('business.pass.color_sample_reward') }}
                </flux:text>
            </div>

            <flux:text class="{{ $marketing ? 'app-role-marketing-pass-detail' : 'app-role-pass-preview-compact' }} text-inherit!">
                {{ $deadline ?? __('business.pass.color_sample_deadline') }}
            </flux:text>
        </div>

        @if (isset($manualCode))
            <aside class="app-pass-preview-manual-code">
                {{ $manualCode }}
            </aside>
        @endif
    </div>
</article>
