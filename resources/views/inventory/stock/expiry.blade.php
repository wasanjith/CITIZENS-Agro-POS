@extends('layouts.app')

@php use App\Domain\Inventory\Support\Qty; @endphp

@section('title', 'Expiring stock')

@section('content')
    <x-ui.page-header title="Expiring stock" description="Batches with stock that expire soon or have already expired. Sell these first, or return or write them off." />

    <x-ui.tabs
        class="mb-4"
        :active="(string) $days"
        :tabs="collect([30, 60, 90, 180])->mapWithKeys(fn ($option) => [(string) $option => ['label' => $option.' days', 'href' => route('inventory.batches.expiry', ['days' => $option])]])->all()"
    />

    @if ($levels->isEmpty())
        <x-ui.empty-state title="Nothing expires within {{ $days }} days" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>Expiry</th>
                <th>Product</th>
                <th>Lot</th>
                <th class="text-right">On hand</th>
                @if ($showCost)
                    <th class="text-right">Value</th>
                @endif
                <th></th>
            </x-slot:head>
            @foreach ($levels as $level)
                @php
                    $expiry = $level->batch->expiry_date;
                    $daysLeft = (int) now()->startOfDay()->diffInDays($expiry, false);
                @endphp
                <tr>
                    <td class="whitespace-nowrap">
                        {{ $expiry->format('Y-m-d') }}
                        @if ($daysLeft < 0)
                            <x-ui.badge color="red">expired</x-ui.badge>
                        @elseif ($daysLeft <= 30)
                            <x-ui.badge color="amber">{{ $daysLeft }} days</x-ui.badge>
                        @else
                            <span class="text-xs text-gray-500">{{ $daysLeft }} days</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('catalog.products.show', $level->product_id) }}" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ $level->variant?->short_code ?? $level->product->short_code }}</a>
                        <span class="font-medium">{{ $level->product->name }} {{ $level->variant?->name }}</span>
                    </td>
                    <td class="text-gray-600">{{ $level->batch->lot_no ?: '—' }}</td>
                    <td class="text-right tabular">{{ Qty::format($level->qty_on_hand) }} {{ $level->product->baseUnit?->symbol }}</td>
                    @if ($showCost)
                        <td class="text-right tabular">{{ number_format((float) $level->qty_on_hand * (float) $level->batch->unit_cost, 2) }}</td>
                    @endif
                    <td class="text-right">
                        @can('create', \App\Domain\Inventory\Models\StockAdjustment::class)
                            <x-ui.button variant="link" :href="route('inventory.adjustments.create', ['batch' => $level->batch_id])">Write off</x-ui.button>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$levels" />
    @endif
@endsection
