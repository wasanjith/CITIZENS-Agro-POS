@props(['title' => null, 'description' => null])

<section {{ $attributes->merge(['class' => 'rounded-lg bg-white shadow-sm ring-1 ring-gray-200']) }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-5 py-4">
            <div>
                @if ($title)
                    <h2 class="text-base font-semibold text-gray-900">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-sm text-gray-500">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif
    <div class="px-5 py-4">
        {{ $slot }}
    </div>
    @isset($footer)
        <footer class="flex justify-end gap-2 rounded-b-lg border-t border-gray-200 bg-gray-50 px-5 py-3">
            {{ $footer }}
        </footer>
    @endisset
</section>
