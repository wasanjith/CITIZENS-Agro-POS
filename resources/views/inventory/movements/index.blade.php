@extends('layouts.app')

@php use App\Domain\Inventory\Support\Qty; @endphp

@section('title', 'Stock movements')

@section('content')
    <x-ui.page-header title="Stock movements" :description="$product ? 'For '.$product->short_code.' · '.$product->name : 'Every change to stock, newest first. Movements are never edited or deleted.'" />

    <form method="GET" action="{{ route('inventory.movements.index') }}" class="mb-4 flex flex-wrap items-end gap-3">
        @if ($product)
            <input type="hidden" name="product" value="{{ $product->id }}">
        @endif
        <x-ui.select name="type" label="Type" :options="$types" :value="request('type')" placeholder="All types" />
        <x-ui.select name="user" label="By" :options="$users" :value="request('user')" placeholder="Anyone" />
        <x-ui.date-input name="from" label="From" :value="request('from')" />
        <x-ui.date-input name="to" label="To" :value="request('to')" />
        <x-ui.button type="submit" variant="secondary">Filter</x-ui.button>
        @if (request()->hasAny(['type', 'user', 'from', 'to', 'product']))
            <x-ui.button variant="link" :href="route('inventory.movements.index')">Reset</x-ui.button>
        @endif
    </form>

    @if ($movements->isEmpty())
        <x-ui.empty-state title="No stock movements" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>When</th>
                <th>Product</th>
                <th>Type</th>
                <th>Batch</th>
                <th class="text-right">Quantity</th>
                @if ($showCost)
                    <th class="text-right">Cost / unit</th>
                @endif
                <th>Reference</th>
                <th>By</th>
            </x-slot:head>
            @foreach ($movements as $movement)
                @php $reference = $movement->reference; @endphp
                <tr>
                    <td class="whitespace-nowrap text-gray-600">{{ $movement->created_at->format('Y-m-d H:i') }}</td>
                    <td>
                        <a href="{{ route('inventory.movements.index', ['product' => $movement->product_id]) }}" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ $movement->variant?->short_code ?? $movement->product->short_code }}</a>
                        <span>{{ $movement->product->name }} {{ $movement->variant?->name }}</span>
                    </td>
                    <td>{{ $movement->type->label() }}</td>
                    <td class="text-gray-600">{{ $movement->batch->isDefault() ? '—' : ($movement->batch->lot_no ?: '#'.$movement->batch_id) }}</td>
                    <td @class(['text-right tabular whitespace-nowrap font-medium', 'text-brand-700' => (float) $movement->qty > 0, 'text-red-700' => (float) $movement->qty < 0])>
                        {{ (float) $movement->qty > 0 ? '+' : '' }}{{ Qty::format($movement->qty) }} {{ $movement->product->baseUnit?->symbol }}
                    </td>
                    @if ($showCost)
                        <td class="text-right tabular text-gray-600">{{ number_format((float) $movement->unit_cost, 2) }}</td>
                    @endif
                    <td>
                        @if ($reference instanceof \App\Domain\Inventory\Support\StockReference)
                            @if ($url = $reference->referenceUrl())
                                <a href="{{ $url }}" class="font-mono text-brand-700 hover:underline">{{ $reference->referenceLabel() }}</a>
                            @else
                                {{ $reference->referenceLabel() }}
                            @endif
                        @else
                            —
                        @endif
                        @if ($movement->note)
                            <p class="text-xs text-gray-500">{{ $movement->note }}</p>
                        @endif
                    </td>
                    <td class="text-gray-600">{{ $movement->user?->name ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        <div class="mt-4">{{ $movements->links() }}</div>
    @endif
@endsection
