<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

@if (isset($description))
    <meta name="description" content="{{ $description }}" />
@endif

@include('partials.icons')

@include('partials.theme-default')
@fluxAppearance

@php
    $nativeValidationMessages = __('validation.native', [], app()->getLocale());
@endphp
<meta name="native-validation" content='@json($nativeValidationMessages)' />

@vite(['resources/css/app.css', 'resources/js/native-validation.js'])
