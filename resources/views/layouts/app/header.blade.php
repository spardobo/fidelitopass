<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink">
        <header data-flux-header class="app-theme [grid-area:header] border-b border-app-line bg-app-canvas">
            <div class="mx-auto grid max-w-[68rem] grid-cols-[auto_1fr_auto] items-center gap-x-2 gap-y-2 px-4 md:gap-x-8 md:px-0 min-[900px]:min-h-[90px] max-[1120px]:md:px-6">
                <a href="{{ route('dashboard') }}" wire:navigate aria-label="FidelitoPass" class="app-focus flex min-h-18 items-center min-[900px]:min-h-11">
                    <img src="{{ asset('logo-header.webp') }}" alt="" class="h-auto w-40 sm:w-48" />
                </a>

                <x-app-navigation class="col-span-3 row-start-3 min-[900px]:col-span-1 min-[900px]:col-start-2 min-[900px]:row-start-1" />

                <div class="contents min-[900px]:col-span-1 min-[900px]:col-start-3 min-[900px]:row-start-1 min-[900px]:flex min-[900px]:flex-wrap min-[900px]:items-center min-[900px]:justify-end min-[900px]:gap-2">
                    <flux:button
                        :href="url('/visits/create')"
                        variant="primary"
                        icon:leading="qr-code"
                        icon:variant="outline"
                        class="app-button-primary col-span-3 row-start-2 min-h-11 justify-self-center px-3! min-[900px]:col-auto min-[900px]:row-auto min-[900px]:justify-self-auto"
                    >
                        {{ __('app-header.register_visit') }}
                    </flux:button>
                    <div class="col-start-3 row-start-1 flex justify-end min-[900px]:col-auto min-[900px]:row-auto">
                        <x-desktop-user-menu />
                    </div>
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
