@props(['type' => 'info', 'title' => null])

@php
    $styles = [
        'info' => 'bg-sky-50 text-sky-800 ring-sky-200',
        'success' => 'bg-brand-50 text-brand-800 ring-brand-200',
        'warning' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'error' => 'bg-red-50 text-red-800 ring-red-200',
    ];
@endphp

<div role="{{ $type === 'error' ? 'alert' : 'status' }}" {{ $attributes->merge(['class' => 'rounded-md p-4 text-sm ring-1 '.($styles[$type] ?? $styles['info'])]) }}>
    @if ($title)
        <p class="font-semibold">{{ $title }}</p>
    @endif
    <div @class(['mt-1' => $title])>{{ $slot }}</div>
</div>
