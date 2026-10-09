@props(['label', 'value', 'icon', 'accent' => 'green', 'href' => null, 'linkLabel' => 'View'])

@php
    // Brand green leads; the other accents only tell the cards apart.
    $accents = [
        'green' => ['border' => 'border-t-brand-500', 'label' => 'text-brand-700', 'icon' => 'bg-brand-50 text-brand-600'],
        'blue' => ['border' => 'border-t-sky-500', 'label' => 'text-sky-700', 'icon' => 'bg-sky-50 text-sky-600'],
        'violet' => ['border' => 'border-t-violet-500', 'label' => 'text-violet-700', 'icon' => 'bg-violet-50 text-violet-600'],
        'amber' => ['border' => 'border-t-amber-500', 'label' => 'text-amber-700', 'icon' => 'bg-amber-50 text-amber-600'],
        'teal' => ['border' => 'border-t-teal-500', 'label' => 'text-teal-700', 'icon' => 'bg-teal-50 text-teal-600'],
    ];
    $style = $accents[$accent] ?? $accents['green'];
@endphp

<div {{ $attributes->merge(['class' => 'flex min-w-0 flex-col rounded-lg border-t-4 bg-white px-3.5 py-2 shadow-sm ring-1 ring-gray-200 '.$style['border']]) }}>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="truncate text-[11px] font-semibold uppercase tracking-wide {{ $style['label'] }}">{{ $label }}</p>
            <p class="mt-0.5 whitespace-nowrap text-xl font-bold leading-tight tabular text-gray-900">{{ $value }}</p>
        </div>
        @isset($visual)
            {{ $visual }}
        @else
            <span class="flex size-9 shrink-0 items-center justify-center rounded-full {{ $style['icon'] }}">
                <x-dashboard.icon :name="$icon" class="size-5" />
            </span>
        @endisset
    </div>
    <div class="mt-1.5 flex items-end justify-between gap-2 text-xs text-gray-600">
        <div class="min-w-0">{{ $slot }}</div>
        @if ($href)
            <a href="{{ $href }}" class="inline-flex shrink-0 items-center gap-0.5 font-semibold text-brand-700 hover:underline">
                {{ $linkLabel }} <x-dashboard.icon name="arrow-right" class="size-3" />
            </a>
        @endif
    </div>
</div>
