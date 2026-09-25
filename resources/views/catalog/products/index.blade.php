@extends('layouts.app')

@section('title', 'Products')

@section('content')
    <x-ui.page-header title="Products" description="Everything the shop sells, with local names, units and prices.">
        <x-ui.button variant="secondary" :href="route('catalog.products.export', request()->query())">Export to Excel</x-ui.button>
        @can('import', \App\Domain\Catalog\Models\Product::class)
            <x-ui.button variant="secondary" :href="route('catalog.products.import')">Import from Excel</x-ui.button>
        @endcan
        @can('create', \App\Domain\Catalog\Models\Product::class)
            <x-ui.button :href="route('catalog.products.create')">Add product</x-ui.button>
        @endcan
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('catalog.products.index')" placeholder="Code, name, Sinhala name or alias" class="mb-4">
        <x-ui.select name="filter[category]" :options="$categories" :value="request('filter.category')" placeholder="All categories" />
        <x-ui.select name="filter[brand]" :options="$brands" :value="request('filter.brand')" placeholder="All brands" />
        <x-ui.select name="filter[status]" :options="['active' => 'Active', 'inactive' => 'Inactive']" :value="request('filter.status')" placeholder="Any status" />
        <x-ui.checkbox name="filter[low]" label="Low stock" :checked="request('filter.low') === '1'" class="self-center" />
    </x-ui.filter-bar>

    @if ($products->isEmpty())
        <x-ui.empty-state title="No products found" description="Add products one by one, or import the full list from Excel.">
            @can('create', \App\Domain\Catalog\Models\Product::class)
                <x-ui.button :href="route('catalog.products.create')">Add product</x-ui.button>
            @endcan
        </x-ui.empty-state>
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="short_code" default="short_code">Code</x-ui.th-sortable>
                <x-ui.th-sortable column="name" default="short_code">Name</x-ui.th-sortable>
                <th>Category</th>
                <th>Units</th>
                <th class="text-right">{{ $priceList?->name ?? 'Price' }}</th>
                <th class="text-right">In stock</th>
                <th>Status</th>
            </x-slot:head>

            @foreach ($products as $product)
                @php $saleUnit = $product->defaultSaleUnit(); @endphp
                <tr>
                    <td class="font-mono font-semibold tabular">
                        <a href="{{ route('catalog.products.show', $product) }}" class="text-brand-700 hover:underline">{{ $product->short_code }}</a>
                    </td>
                    <td>
                        <a href="{{ route('catalog.products.show', $product) }}" class="font-medium hover:underline">{{ $product->name }}</a>
                        @if ($product->name_si)
                            <p class="font-sinhala text-xs text-gray-600">{{ $product->name_si }}</p>
                        @endif
                        @if ($product->has_variants)
                            <x-ui.badge color="blue">variants</x-ui.badge>
                        @endif
                    </td>
                    <td class="text-gray-600">
                        {{ $product->category?->name }}
                        @if ($product->brand)
                            <p class="text-xs text-gray-500">{{ $product->brand->name }}</p>
                        @endif
                    </td>
                    <td class="text-gray-600">{{ $product->units->map(fn ($unit) => $unit->unit->symbol)->implode(' · ') }}</td>
                    <td class="text-right tabular">
                        @if ($saleUnit && isset($prices[$product->id][$saleUnit->unit_id]))
                            {{ number_format((float) $prices[$product->id][$saleUnit->unit_id], 2) }}
                            <span class="text-xs text-gray-500">/ {{ $saleUnit->unit->symbol }}</span>
                        @else
                            <span class="text-amber-700">No price</span>
                        @endif
                    </td>
                    @php $isLow = (float) $product->reorder_level > 0 && (float) $product->on_hand <= (float) $product->reorder_level; @endphp
                    <td @class(['text-right tabular whitespace-nowrap', 'font-semibold text-amber-700' => $isLow])>
                        {{ \App\Domain\Inventory\Support\Qty::format((string) $product->on_hand) }} {{ $product->baseUnit?->symbol }}
                    </td>
                    <td>
                        <x-ui.badge :color="$product->is_active ? 'green' : 'red'">{{ $product->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$products" />
    @endif
@endsection
