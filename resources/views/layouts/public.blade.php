<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        {{ $slot }}

        <flux:toast.group>
            <flux:toast class="app-theme" />
        </flux:toast.group>

        @if (session()->pull('account.feedback') === 'deleted')
            <span
                class="hidden"
                x-data
                x-init="$nextTick(() => requestAnimationFrame(() => $flux.toast({ text: @js(__('account-feedback.deleted')), variant: 'success' })))"
            ></span>
        @endif

        @fluxScripts
    </body>
</html>
