<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-md flex-col gap-6">
                <a href="{{ route('home') }}" class="flex justify-center rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#B7ABE4]" wire:navigate>
                    <img src="{{ asset('logo-header.webp') }}" alt="{{ config('app.name', 'Laravel') }}" class="h-auto w-full max-w-52" />
                </a>

                <div class="flex flex-col gap-6">
                    <div class="rounded-2xl border border-[#414141] bg-[#272727] text-ink shadow-sm">
                        <div class="px-6 py-8 sm:px-10">{{ $slot }}</div>
                    </div>
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
