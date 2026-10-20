@props(['id', 'success' => false])

<div data-modal-step-heading class="flex min-w-0 items-center gap-4">
    <span @class([
        'flex size-16 shrink-0 items-center justify-center rounded-full',
        'bg-app-emphasis text-app-accent' => ! $success,
        'bg-app-success-surface text-app-success-ink' => $success,
    ]) aria-hidden="true">
        {{ $icon }}
    </span>
    <div class="flex min-w-0 flex-wrap items-center gap-2">
        <h3 id="{{ $id }}" class="app-role-section min-w-0 break-words text-app-ink">
            {{ $slot }}
        </h3>
        {{ $badge ?? '' }}
    </div>
</div>
