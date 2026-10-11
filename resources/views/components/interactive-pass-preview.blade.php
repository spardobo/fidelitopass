@props([
    'businessName',
    'ariaLabel' => null,
    'backgroundColor' => null,
    'textColor' => null,
    'neutral' => false,
    'showCredentials' => true,
    'reward' => null,
    'rewardDescription' => null,
    'progress' => null,
    'extraPoints' => null,
    'deadline' => null,
])

<div data-thumbnail x-data="passPreviewMotion" class="landing-thumbnail relative w-full max-w-[576px] @container/pass-preview">
    <x-pass-preview
        :business-name="$businessName"
        :aria-label="$ariaLabel"
        :background-color="$backgroundColor"
        :text-color="$textColor"
        :marketing="true"
        :sheen="true"
        :show-credentials="$showCredentials"
        :reward="$reward"
        :reward-description="$rewardDescription"
        :progress="$progress"
        :extra-points="$extraPoints"
        :deadline="$deadline"
        x-on:pointermove="move($event)"
        x-on:pointerleave="reset()"
        {{ $attributes->class([
            'isolate origin-center shadow-2xl',
            'bg-app-surface text-app-ink' => $neutral,
            'bg-app-accent text-app-on-accent' => ! $neutral,
            'bg-[linear-gradient(115deg,transparent_12%,rgb(var(--pass-sheen-rgb,255_255_255)/24%)_34%,rgb(var(--pass-sheen-rgb,255_255_255)/8%)_50%,transparent_72%)] bg-size-[160%_160%] bg-position-[var(--sheen-x,50%)_var(--sheen-y,50%)] motion-safe:[transition:transform_180ms_cubic-bezier(0.32,0.72,0,1),background-position_180ms_cubic-bezier(0.32,0.72,0,1)]',
        ]) }}
    />
</div>
