@props(['label', 'value', 'hint' => null, 'href' => null])

<div {{ $attributes->merge(['class' => 'rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200']) }}>
    <p class="text-sm font-medium text-gray-500">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tabular text-gray-900">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
    @if ($href)
        <a href="{{ $href }}" class="mt-2 inline-block text-xs font-medium text-brand-700 hover:underline">View &rarr;</a>
    @endif
</div>
