@props(['label', 'type' => 'date', 'min' => null])

@php
    $options = [
        'locale' => str_replace('_', '-', app()->getLocale()),
        'labels' => __('date-time-picker'),
        'allowInput' => false,
        'allowInvalidPreload' => true,
        'altInput' => true,
        'altFormat' => 'regional',
        'ariaDateFormat' => 'regional',
        'dateFormat' => $type === 'date' ? 'Y-m-d' : 'H:i',
        'enableTime' => $type === 'time',
        'noCalendar' => $type === 'time',
        'disableMobile' => true,
        'minuteIncrement' => 1,
        'minDate' => $min,
    ];
    $model = $attributes->wire('model')->value();
    $id = $attributes->get('id');
    $picker = 'dateTimePicker('.\Illuminate\Support\Js::from($model).', '.\Illuminate\Support\Js::from($options).')';
@endphp

<div class="min-w-0 space-y-3">
    <label for="{{ $id }}" class="app-label! block">
        @if ($attributes->has('required'))
            <span aria-hidden="true" class="text-app-danger-ink me-1">*</span>
        @endif
        {{ $label }}
    </label>
    <div wire:ignore x-data="{{ $picker }}" data-date-time-picker="{{ $type }}">
        <flux:input
            type="text"
            x-ref="input"
            :min="$min"
            :icon:trailing="$type === 'date' ? 'calendar-days' : null"
            aria-describedby="{{ $id }}-error"
            {{ $attributes }}
        />
    </div>
    <flux:error :name="$model" id="{{ $id }}-error" class="app-error" />
</div>
