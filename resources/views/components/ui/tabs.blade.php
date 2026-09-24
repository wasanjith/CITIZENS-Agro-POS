{{-- Link tabs: <x-ui.tabs :tabs="['shop' => ['label' => 'Shop', 'href' => '…']]" active="shop" /> --}}
@props(['tabs' => [], 'active' => null])

<nav {{ $attributes->merge(['class' => 'flex gap-1 overflow-x-auto border-b border-gray-200']) }} aria-label="Tabs">
    @foreach ($tabs as $key => $tab)
        <a
            href="{{ $tab['href'] }}"
            @class([
                'whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium',
                'border-brand-600 text-brand-700' => $key === $active,
                'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' => $key !== $active,
            ])
            @if ($key === $active) aria-current="page" @endif
        >{{ $tab['label'] }}</a>
    @endforeach
</nav>
