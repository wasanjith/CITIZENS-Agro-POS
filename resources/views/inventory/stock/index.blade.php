@extends('layouts.app')

@php use App\Domain\Inventory\Support\Qty; @endphp

@section('title', 'Stock on hand')

@section('content')
    <x-ui.page-header title="Stock on hand" :description="$totalValue !== null ? 'Stock value at cost: Rs. '.number_format((float) $totalValue, 2) : null">
        @can('create', \App\Domain\Inventory\Models\StockAdjustment::class)
            <x-ui.button variant="secondary" :href="route('inventory.adjustments.create')">Adjust stock</x-ui.button>
        @endcan
        @can('create', \App\Domain\Purchasing\Models\PurchaseOrder::class)
            <x-ui.button variant="secondary" :href="route('purchasing.purchase-orders.create')">New purchase order</x-ui.button>
        @endcan
    </x-ui.page-header>

    @if ($pendingOpening > 0)
        <x-ui.alert type="warning" title="Opening stock not in inventory yet" class="mb-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ $pendingOpening }} opening stock {{ str('entry')->plural($pendingOpening) }} from an earlier product import still have to be added to stock.</span>
                <form method="POST" action="{{ route('inventory.stock.post-opening') }}">
                    @csrf
                    <x-ui.button type="submit" size="sm">Add opening stock now</x-ui.button>
                </form>
            </div>
        </x-ui.alert>
    @endif

    <x-ui.tabs
        class="mb-4"
        :active="$byBatch ? 'batches' : (request('filter.low') ? 'low' : 'products')"
        :tabs="[
            'products' => ['label' => 'By product', 'href' => route('inventory.stock.index')],
            'batches' => ['label' => 'By batch', 'href' => route('inventory.stock.index', ['view' => 'batches'])],
            'low' => ['label' => 'Low stock', 'href' => route('inventory.stock.index', ['filter' => ['low' => 1]])],
        ]"
    />

    <x-ui.filter-bar :action="route('inventory.stock.index')" :placeholder="$byBatch ? 'Code, name or lot no.' : 'Code, name or alias'" class="mb-4">
        @if ($byBatch)
            <input type="hidden" name="view" value="batches">
        @endif
        <x-ui.select name="filter[category]" :options="$categories" :value="request('filter.category')" placeholder="All categories" />
        @unless ($byBatch)
            <x-ui.select name="filter[stock]" :options="['in' => 'In stock', 'out' => 'Out of stock']" :value="request('filter.stock')" placeholder="Any stock" />
            <x-ui.checkbox name="filter[low]" label="At or below reorder level" :checked="request('filter.low') === '1'" class="self-center" />
        @endunless
    </x-ui.filter-bar>

    @if ($rows->isEmpty())
        <x-ui.empty-state title="Nothing to show" description="Stock appears here once goods are received, opening stock is added or stock is adjusted." />
    @elseif ($byBatch)
        <x-ui.table>
            <x-slot:head>
                <th>Product</th>
                <th>Batch</th>
                <th>Expiry</th>
                <th class="text-right">On hand</th>
                <th class="text-right">Reserved</th>
                @if ($showCost)
                    <th class="text-right">Cost / unit</th>
                    <th class="text-right">Value</th>
                @endif
            </x-slot:head>
            @foreach ($rows as $level)
                <tr>
                    <td>
                        <a href="{{ route('catalog.products.show', $level->product_id) }}" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ $level->variant?->short_code ?? $level->product->short_code }}</a>
                        <span class="font-medium">{{ $level->product->name }} {{ $level->variant?->name }}</span>
                    </td>
                    <td class="text-gray-600">{{ $level->batch->isDefault() ? 'General stock' : ($level->batch->lot_no ? 'Lot '.$level->batch->lot_no : 'Received '.$level->batch->received_at->format('Y-m-d')) }}</td>
                    <td class="whitespace-nowrap @if ($level->batch->expiry_date?->isPast()) font-semibold text-red-700 @endif">{{ $level->batch->expiry_date?->format('Y-m-d') ?? '—' }}</td>
                    <td class="text-right tabular @if ((float) $level->qty_on_hand < 0) text-red-700 @endif">{{ Qty::format($level->qty_on_hand) }} {{ $level->product->baseUnit?->symbol }}</td>
                    <td class="text-right tabular text-gray-600">{{ (float) $level->qty_reserved ? Qty::format($level->qty_reserved) : '—' }}</td>
                    @if ($showCost)
                        <td class="text-right tabular text-gray-600">{{ number_format((float) $level->batch->unit_cost, 2) }}</td>
                        <td class="text-right tabular">{{ number_format((float) $level->qty_on_hand * (float) $level->batch->unit_cost, 2) }}</td>
                    @endif
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$rows" />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="short_code" default="short_code">Code</x-ui.th-sortable>
                <x-ui.th-sortable column="name" default="short_code">Name</x-ui.th-sortable>
                <th>Category</th>
                <x-ui.th-sortable column="on_hand" default="short_code" class="text-right">On hand</x-ui.th-sortable>
                <th class="text-right">Reserved</th>
                <th class="text-right">Reorder level</th>
                @if ($showCost)
                    <th class="text-right">Value</th>
                @endif
            </x-slot:head>
            @foreach ($rows as $product)
                @php
                    $onHand = (float) $product->on_hand;
                    $isLow = (float) $product->reorder_level > 0 && $onHand <= (float) $product->reorder_level;
                @endphp
                <tr>
                    <td class="font-mono font-semibold tabular"><a href="{{ route('catalog.products.show', $product) }}" class="text-brand-700 hover:underline">{{ $product->short_code }}</a></td>
                    <td>
                        <a href="{{ route('catalog.products.show', $product) }}" class="font-medium hover:underline">{{ $product->name }}</a>
                        @if ($product->name_si)
                            <p class="font-sinhala text-xs text-gray-600">{{ $product->name_si }}</p>
                        @endif
                    </td>
                    <td class="text-gray-600">{{ $product->category?->name }}</td>
                    <td @class(['text-right tabular whitespace-nowrap', 'font-semibold text-amber-700' => $isLow, 'text-red-700' => $onHand < 0])>
                        {{ Qty::format((string) $product->on_hand) }} {{ $product->baseUnit?->symbol }}
                        @if ($isLow)
                            <x-ui.badge color="amber">low</x-ui.badge>
                        @endif
                    </td>
                    <td class="text-right tabular text-gray-600">{{ (float) $product->reserved ? Qty::format((string) $product->reserved) : '—' }}</td>
                    <td class="text-right tabular text-gray-600">{{ (float) $product->reorder_level ? Qty::format($product->reorder_level) : '—' }}</td>
                    @if ($showCost)
                        <td class="text-right tabular">{{ number_format((float) $product->stock_value, 2) }}</td>
                    @endif
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$rows" />
    @endif
@endsection
