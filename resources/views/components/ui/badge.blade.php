@props(['color' => 'gray'])

@php
    $colors = [
        'gray' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        'green' => 'bg-brand-50 text-brand-700 ring-brand-600/20',
        'red' => 'bg-red-50 text-red-700 ring-red-600/20',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'purple' => 'bg-violet-50 text-violet-700 ring-violet-600/20',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset '.($colors[$color] ?? $colors['gray'])]) }}>
    {{ $slot }}
</span>
