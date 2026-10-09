@props(['segments', 'caption'])

{{-- $segments: list of ['label' => string, 'value' => float, 'display' => string]. The ring's circumference is 100, so a segment's length is its percentage. --}}
@php
    $colors = [
        ['stroke' => 'stroke-brand-600', 'dot' => 'bg-brand-600'],
        ['stroke' => 'stroke-sky-500', 'dot' => 'bg-sky-500'],
        ['stroke' => 'stroke-amber-500', 'dot' => 'bg-amber-500'],
        ['stroke' => 'stroke-violet-500', 'dot' => 'bg-violet-500'],
        ['stroke' => 'stroke-teal-500', 'dot' => 'bg-teal-500'],
    ];
    $total = array_sum(array_map(fn ($segment) => max(0, $segment['value']), $segments));
    $offset = 25;
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center justify-center gap-x-4 gap-y-2']) }}>
    <svg viewBox="0 0 42 42" class="size-24 shrink-0" aria-hidden="true">
        <circle cx="21" cy="21" r="15.9155" fill="none" class="stroke-gray-100" stroke-width="6" />
        @if ($total > 0)
            @foreach ($segments as $index => $segment)
                @php
                    $percent = max(0, $segment['value']) / $total * 100;
                @endphp
                @if ($percent > 0)
                    <circle cx="21" cy="21" r="15.9155" fill="none" class="{{ $colors[$index % count($colors)]['stroke'] }}" stroke-width="6"
                        stroke-dasharray="{{ round($percent, 3) }} {{ round(100 - $percent, 3) }}" stroke-dashoffset="{{ round($offset, 3) }}" />
                    @php $offset -= $percent; @endphp
                @endif
            @endforeach
        @endif
    </svg>
    <ul class="min-w-[7.5rem] flex-1 space-y-1.5 text-xs">
        @foreach ($segments as $index => $segment)
            <li class="flex items-center gap-2">
                <span class="size-2.5 shrink-0 rounded-full {{ $colors[$index % count($colors)]['dot'] }}"></span>
                <span class="min-w-0 flex-1 truncate text-gray-700">{{ $segment['label'] }}</span>
                <span class="font-semibold tabular text-gray-900">{{ $total > 0 ? round(max(0, $segment['value']) / $total * 100) : 0 }}%</span>
            </li>
        @endforeach
    </ul>
    <table class="sr-only">
        <caption>{{ $caption }}</caption>
        @foreach ($segments as $segment)
            <tr><th>{{ $segment['label'] }}</th><td>{{ $segment['display'] }}</td></tr>
        @endforeach
    </table>
</div>
