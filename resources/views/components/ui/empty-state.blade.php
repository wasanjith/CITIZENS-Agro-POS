@props(['title' => 'Nothing here yet', 'description' => null])

<div {{ $attributes->merge(['class' => 'rounded-lg border-2 border-dashed border-gray-200 px-6 py-10 text-center']) }}>
    <p class="text-sm font-semibold text-gray-900">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
