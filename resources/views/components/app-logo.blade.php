@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-[#B7ABE4] text-[#181818]">
            <img src="{{ asset('logo_icon.svg') }}" alt="" class="size-5 object-contain brightness-0" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-[#B7ABE4] text-[#181818]">
            <img src="{{ asset('logo_icon.svg') }}" alt="" class="size-5 object-contain brightness-0" />
        </x-slot>
    </flux:brand>
@endif
