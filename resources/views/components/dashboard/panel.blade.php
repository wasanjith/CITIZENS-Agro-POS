@props(['title', 'count' => null, 'countColor' => 'red', 'href' => null, 'linkLabel' => 'View all', 'titleClass' => 'text-gray-900'])

{{-- Compact card: the "View all" link sits in the header so the body keeps every row. --}}
<section {{ $attributes->merge(['class' => 'flex min-w-0 flex-col rounded-lg bg-white shadow-sm ring-1 ring-gray-200']) }}>
    <header class="flex items-center gap-2 px-3.5 pt-2 pb-1">
        <h2 class="truncate text-xs font-semibold uppercase tracking-wide {{ $titleClass }}">{{ $title }}</h2>
        @if ($count !== null && $count > 0)
            <span @class([
                'rounded px-1.5 text-xs font-bold tabular text-white',
                'bg-red-600' => $countColor === 'red',
                'bg-amber-500' => $countColor === 'amber',
            ])>{{ $count }}</span>
        @endif
        <div class="ml-auto flex shrink-0 items-center gap-3">
            {{ $actions ?? '' }}
            @if ($href)
                <a href="{{ $href }}" class="inline-flex items-center gap-0.5 text-xs font-semibold text-brand-700 hover:underline" title="{{ $linkLabel }}" aria-label="{{ $linkLabel }}: {{ $title }}">
                    <span class="hidden 2xl:inline">{{ $linkLabel }}</span> <x-dashboard.icon name="arrow-right" class="size-3.5" />
                </a>
            @endif
        </div>
    </header>
    <div class="flex-1 px-3.5 pb-1.5">
        {{ $slot }}
    </div>
    @isset($footer)
        <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 px-3.5 py-1.5">
            {{ $footer }}
        </footer>
    @endisset
</section>
