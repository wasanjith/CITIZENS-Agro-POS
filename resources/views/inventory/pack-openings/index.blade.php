@extends('layouts.app')

@php
    use App\Domain\Inventory\Support\Qty;
    $showCost = auth()->user()->can('viewCost', \App\Domain\Catalog\Models\Product::class);
@endphp

@section('title', 'Opened packs')

@section('content')
    <x-ui.page-header title="Opened packs" description="Sealed bags opened into loose stock. Bags opened when a delivery arrives are entered on the goods receipt.">
        @can('create', \App\Domain\Inventory\Models\PackOpening::class)
            <x-ui.button :href="route('inventory.pack-openings.create')">Open packs</x-ui.button>
        @endcan
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('inventory.pack-openings.index')" placeholder="Number or note" class="mb-4" />

    @if ($openings->isEmpty())
        <x-ui.empty-state title="No packs opened yet" />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="number" default="created_at">Number</x-ui.th-sortable>
                <x-ui.th-sortable column="created_at" default="created_at">Date</x-ui.th-sortable>
                <th>Opened</th>
                <th>Into</th>
                <th class="text-right">Weighed</th>
                @if ($showCost)
                    <x-ui.th-sortable column="cost_total" default="created_at" class="text-right">Cost</x-ui.th-sortable>
                @endif
                <th>From</th>
                <th>By</th>
            </x-slot:head>
            @foreach ($openings as $opening)
                <tr>
                    <td class="font-mono font-semibold"><a href="{{ route('inventory.pack-openings.show', $opening) }}" class="text-brand-700 hover:underline">{{ $opening->number }}</a></td>
                    <td class="whitespace-nowrap text-gray-600">{{ $opening->created_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ Qty::format($opening->packs) }} {{ $opening->sealedProduct->baseUnit?->symbol }} · {{ $opening->sealedProduct->name }}</td>
                    <td>{{ $opening->looseProduct->name }}</td>
                    <td class="text-right tabular whitespace-nowrap">
                        {{ Qty::format($opening->weighed_qty) }} {{ $opening->looseProduct->baseUnit?->symbol }}
                        @if (! $opening->difference()->isZero())
                            <span @class(['block text-xs', 'text-red-700' => $opening->difference()->isNegative(), 'text-gray-500' => $opening->difference()->isPositive()])>{{ $opening->difference()->isPositive() ? '+' : '' }}{{ Qty::format($opening->difference()) }}</span>
                        @endif
                    </td>
                    @if ($showCost)
                        <td class="text-right tabular">{{ number_format((float) $opening->cost_total, 2) }}</td>
                    @endif
                    <td class="text-gray-600">
                        @if ($opening->goodsReceipt)
                            <a href="{{ route('purchasing.goods-receipts.show', $opening->goodsReceipt) }}" class="font-mono text-brand-700 hover:underline">{{ $opening->goodsReceipt->number }}</a>
                        @else
                            Shop stock
                        @endif
                    </td>
                    <td class="text-gray-600">{{ $opening->creator?->name ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$openings" />
    @endif
@endsection
