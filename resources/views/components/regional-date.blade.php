@props(['date', 'endDate' => null])

@php
    $startFallback = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->format('m/d/Y');
    $endFallback = $endDate === null ? null : \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $endDate, 'UTC')->format('m/d/Y');
@endphp

<time
    datetime="{{ $date }}"
    x-data x-text="$regionalDates.date(@js($date))"
    {{ $attributes }}
>{{ $startFallback }}</time>@if ($endDate !== null) – <time
    datetime="{{ $endDate }}"
    x-data x-text="$regionalDates.date(@js($endDate))"
    {{ $attributes }}
>{{ $endFallback }}</time>@endif
