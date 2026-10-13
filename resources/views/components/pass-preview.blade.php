@props([
    'businessName',
    'ariaLabel' => null,
    'backgroundColor' => null,
    'textColor' => null,
    'marketing' => false,
    'sheen' => false,
    'showCredentials' => false,
    'reward' => null,
    'rewardDescription' => null,
    'progress' => null,
    'extraPoints' => null,
    'deadline' => null,
])

@php
    if ($backgroundColor && $textColor === null) {
        $relativeLuminance = static function (string $hex): float {
            $channels = array_map(static function (string $channel): float {
                $normalized = hexdec($channel) / 255;

                return $normalized <= 0.04045 ? $normalized / 12.92 : (($normalized + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($hex, 1), 2));

            return $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;
        };
        $luminance = $relativeLuminance($backgroundColor);
        $darkInkLuminance = $relativeLuminance('#17131F');
        $darkContrast = (max($luminance, $darkInkLuminance) + 0.05) / (min($luminance, $darkInkLuminance) + 0.05);
        $textColor = '#17131F';
        if ($darkContrast < 4.5) {
            $textColor = 1.05 / ($luminance + 0.05) >= 4.5 ? '#FFFFFF' : '#000000';
        }
    }
@endphp

<article
    @if ($showCredentials)
        aria-description="{{ __('business.pass.illustrative_notice') }}"
    @endif
    aria-label="{{ $ariaLabel ?? __('business.pass.illustrative_pass') }}"
    {{ $attributes->class(['app-pass-preview', 'app-pass-preview--with-qr app-pass-preview--with-manual-code' => $showCredentials, 'app-pass-preview--marketing' => $marketing]) }}
    @if ($backgroundColor && $textColor)
        style="background-color: {{ $backgroundColor }}; color: {{ $textColor }}{{ $sheen ? '; --pass-sheen-rgb: '.($textColor === '#FFFFFF' ? '0 0 0' : '255 255 255') : '' }}"
    @endif
>
    <div class="app-pass-preview-header items-center!">
        <flux:heading level="2" class="app-role-pass-preview-brand min-w-0 wrap-anywhere text-inherit!">
            {{ $businessName }}
        </flux:heading>
        <x-pass-brand-mark />
    </div>

    <div class="app-pass-preview-content">
        <div class="app-pass-preview-copy wrap-anywhere">
            <div class="app-pass-preview-details">
                <div class="app-role-pass-preview-label flex min-w-0 items-start gap-1 text-inherit">
                    <span class="inline-grid h-[1lh] w-4 shrink-0 place-items-center">
                        <flux:icon.tag variant="outline" class="app-pass-preview-promotion-icon size-4" />
                    </span>
                    <flux:heading level="3" class="app-role-pass-preview-label min-w-0 text-inherit!">
                        {{ __('business.pass.color_sample_label') }}
                    </flux:heading>
                </div>
                <flux:text class="app-role-pass-preview-reward min-w-0 text-inherit!">
                    {{ $reward ?? __('business.pass.color_sample_reward') }}
                </flux:text>
                @if ($rewardDescription !== null && $rewardDescription !== '')
                    <flux:text class="app-role-pass-preview-compact text-inherit!">
                        {{ $rewardDescription }}
                    </flux:text>
                @endif
            </div>
            <flux:text class="app-role-pass-preview-metric text-inherit!">
                {{ $progress ?? __('business.pass.color_sample_progress') }}
            </flux:text>
            @if ($extraPoints !== null && $extraPoints !== '')
                <div class="app-pass-preview-extra-points app-role-pass-preview-extra flex min-w-0 items-start gap-1 text-inherit">
                    <span class="inline-grid h-[1lh] w-4 shrink-0 place-items-center">
                        <flux:icon.bolt variant="outline" class="size-4" />
                    </span>
                    <flux:text class="app-role-pass-preview-extra min-w-0 text-inherit!">
                        {{ $extraPoints }}
                    </flux:text>
                </div>
            @endif
        </div>
        @if ($showCredentials)
            <aside class="app-pass-preview-qr">
                <img src="{{ asset('preview-pass-qr.svg') }}" alt="{{ __('landing.hero.sample.qr_alt') }}" class="app-pass-preview-qr-image relative z-1 aspect-square w-full max-w-32 shrink-0 bg-white" width="232" height="232">
            </aside>
        @endif
    </div>

    <div class="app-pass-preview-reward">
        <div class="app-pass-preview-validity app-role-pass-preview-validity flex min-w-0 items-start gap-2">
            <span class="inline-grid h-[1lh] w-4 shrink-0 place-items-center">
                <flux:icon.calendar-days variant="outline" class="size-4" />
            </span>
            <flux:text class="app-role-pass-preview-validity min-w-0 wrap-anywhere text-inherit!">
                {{ $deadline ?? __('business.pass.color_sample_deadline') }}
            </flux:text>
        </div>
        @if ($showCredentials)
            <aside class="app-pass-preview-manual-code">
                <flux:text class="app-role-pass-preview-manual-label text-inherit!">
                    {{ __('landing.hero.sample.manual_code_label') }}
                    <span class="app-role-pass-preview-manual-value block tracking-widest">{{ __('landing.hero.sample.manual_code') }}</span>
                </flux:text>
            </aside>
        @endif
    </div>
</article>
