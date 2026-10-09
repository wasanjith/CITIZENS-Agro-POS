@props(['values'])

{{-- A small running-total line (no axes); purely decorative, the figure itself is printed next to it. --}}
@php
    $count = count($values);
    $max = max(1.0, ...array_map('floatval', $values ?: [0]));
    $points = collect($values)->values()->map(fn ($value, $index) => round($count > 1 ? $index / ($count - 1) * 96 + 2 : 50, 2).','.round(30 - max(0, (float) $value) / $max * 26, 2))->implode(' ');
@endphp

<svg viewBox="0 0 100 32" preserveAspectRatio="none" {{ $attributes->merge(['class' => 'h-9 w-16 shrink-0']) }} aria-hidden="true">
    @if ($count > 1)
        <polygon points="2,32 {{ $points }} 98,32" class="fill-brand-100" />
        <polyline points="{{ $points }}" fill="none" class="stroke-brand-500" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
    @endif
</svg>
