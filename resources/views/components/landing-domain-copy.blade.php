@props(['text'])

@php
    $parts = preg_split('/\b(negocios?|clientes?|promoci(?:ón|ones)|tarjetas?|recompensas?)\b/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
@endphp

{{-- keep inline spacing and punctuation exactly as supplied by the translation --}}
@foreach ($parts as $index => $part)@if ($index % 2 === 1)<strong>{{ ucfirst($part) }}</strong>@else{{ $part }}@endif@endforeach
