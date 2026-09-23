<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

@if (isset($description))
    <meta name="description" content="{{ $description }}" />
@endif

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite('resources/css/app.css')
