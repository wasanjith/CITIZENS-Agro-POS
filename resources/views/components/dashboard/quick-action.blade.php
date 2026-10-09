@props(['href', 'icon', 'label', 'short' => null, 'accent' => 'green'])

@php
    $accents = [
        'green' => 'bg-brand-50 text-brand-700 ring-brand-200 hover:bg-brand-100',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-200 hover:bg-sky-100',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200 hover:bg-violet-100',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-200 hover:bg-amber-100',
        'teal' => 'bg-teal-50 text-teal-700 ring-teal-200 hover:bg-teal-100',
        'red' => 'bg-red-50 text-red-700 ring-red-200 hover:bg-red-100',
        'gray' => 'bg-gray-50 text-gray-700 ring-gray-200 hover:bg-gray-100',
    ];
@endphp

<a href="{{ $href }}" title="{{ $label }}" {{ $attributes->merge(['class' => 'flex items-center gap-1.5 rounded-md px-2.5 py-2 text-xs font-semibold ring-1 transition '.($accents[$accent] ?? $accents['green'])]) }}>
    <x-dashboard.icon :name="$icon" class="size-5 shrink-0" />
    @if ($short)
        <span class="truncate 2xl:hidden">{{ $short }}</span>
        <span class="hidden truncate 2xl:inline">{{ $label }}</span>
    @else
        <span class="truncate">{{ $label }}</span>
    @endif
</a>
