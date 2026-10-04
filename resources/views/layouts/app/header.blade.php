<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink">
        <header data-flux-header class="app-theme [grid-area:header] border-b border-app-line bg-app-canvas">
            <div class="mx-auto grid max-w-[68rem] grid-cols-[auto_1fr_auto] items-center gap-x-2 px-4 md:gap-x-8 md:px-0 min-[900px]:min-h-18 max-[1120px]:md:px-6">
                <a href="{{ route('dashboard') }}" wire:navigate aria-label="FidelitoPass" class="app-focus flex min-h-18 items-center">
                    <img src="{{ asset('logo-header.webp') }}" alt="" class="h-auto w-25 md:w-[142px]" />
                </a>

                <x-app-navigation class="col-span-3 row-start-2 min-[900px]:col-span-1 min-[900px]:col-start-2 min-[900px]:row-start-1" />

                <div class="col-span-2 col-start-2 row-start-1 flex flex-wrap items-center justify-end gap-2 min-[900px]:col-span-1 min-[900px]:col-start-3">
                    <flux:button :href="url('/visits/create')" variant="primary" class="app-button-primary px-3!">
                        {{ __('Register visit') }}
                    </flux:button>
                    <x-desktop-user-menu />
                </div>
            </div>
        </header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
