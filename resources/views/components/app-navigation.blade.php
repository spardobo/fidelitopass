@php
    $destinations = [
        ['label' => __('app-navigation.summary'), 'url' => route('dashboard'), 'icon' => 'home', 'current' => request()->routeIs('dashboard')],
        ['label' => __('app-navigation.pass'), 'url' => url('/pass'), 'icon' => 'credit-card', 'current' => request()->is('pass', 'pass/*')],
        ['label' => __('app-navigation.invite_customers'), 'url' => url('/invite'), 'icon' => 'qr-code', 'current' => request()->is('invite', 'invite/*')],
    ];
@endphp

{{-- native anchors preserve the intrinsic marker without Flux's outer-link underline --}}
<nav aria-label="{{ __('app-navigation.main_navigation') }}" {{ $attributes->class('flex flex-wrap items-center justify-between gap-x-4 min-[900px]:justify-start min-[900px]:gap-x-6') }}>
    @foreach ($destinations as $destination)
        <a
            href="{{ $destination['url'] }}"
            wire:navigate
            @if ($destination['current']) aria-current="page" @endif
            class="app-role-action app-focus flex min-h-12 items-center whitespace-nowrap text-app-ink-secondary hover:text-app-accent-text aria-[current=page]:text-app-accent-text min-[900px]:min-h-11"
        >
            <span @class([
                'app-navigation-content relative inline-flex items-center gap-2',
                'after:pointer-events-none after:absolute after:inset-s-0 after:inset-e-0 after:-bottom-1.5 after:h-0.5 after:rounded-full after:bg-app-accent after:content-[\'\']' => $destination['current'],
            ])>
                <flux:icon :name="$destination['icon']" variant="outline" class="hidden size-5 min-[900px]:block" />
                <span>
                    {{ $destination['label'] }}
                </span>
            </span>
        </a>
    @endforeach
</nav>
