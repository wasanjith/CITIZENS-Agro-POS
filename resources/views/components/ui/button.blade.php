@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
])

@php
    $variants = [
        'primary' => 'bg-brand-600 text-white hover:bg-brand-700 focus-visible:outline-brand-600 shadow-sm',
        'secondary' => 'bg-white text-gray-800 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 shadow-sm',
        'danger' => 'bg-red-600 text-white hover:bg-red-700 focus-visible:outline-red-600 shadow-sm',
        'ghost' => 'text-gray-700 hover:bg-gray-100',
        'link' => 'text-brand-700 hover:text-brand-800 underline-offset-4 hover:underline',
    ];
    $sizes = [
        'sm' => 'px-2.5 py-1.5 text-xs',
        'md' => 'px-3.5 py-2 text-sm',
        'lg' => 'px-5 py-3 text-base',
    ];
    $classes = 'inline-flex items-center justify-center gap-2 rounded-md font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50 '
        .($variants[$variant] ?? $variants['primary']).' '.($variant === 'link' ? 'text-sm' : ($sizes[$size] ?? $sizes['md']));
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
