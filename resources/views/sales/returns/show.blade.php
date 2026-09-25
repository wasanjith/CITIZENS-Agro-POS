@extends('layouts.app')

@php
    use App\Domain\Inventory\Support\Qty;
    $money = fn ($value) => number_format((float) $value, 2);
@endphp

@section('title', $return->number)

@section('content')
    <x-ui.page-header :title="'Return '.$return->number" :description="$return->created_at->format('Y-m-d H:i').' · '.$return->creator->name">
        <x-ui.button variant="secondary" :href="route('pos.returns.receipt', $return)" target="_blank">80 mm</x-ui.button>
        @if ($canReprint)
            <form method="POST" action="{{ route('pos.returns.reprint', $return) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">Reprint (COPY)</x-ui.button>
            </form>
        @endif
        <x-ui.button :href="route('sales.show', $return->sale)">Invoice {{ $return->sale->invoice_no }}</x-ui.button>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Items" class="lg:col-span-2">
            <x-ui.table class="shadow-none ring-0">
                <x-slot:head>
                    <th>Item</th>
                    <th class="text-right">Qty</th>
                    <th>Stock</th>
                    <th class="text-right">Refund</th>
                    @if ($showCost)<th class="text-right">Cost</th>@endif
                </x-slot:head>
                @foreach ($return->lines as $line)
                    <tr>
                        <td>
                            <span class="font-medium">{{ $line->saleItem->name_snapshot }}</span>
                            @if ($showCost)
                                <span class="block text-xs text-gray-500">@foreach ($line->batches as $batch){{ $batch->batch->label() }}: {{ Qty::format($batch->base_qty) }}@if (! $loop->last); @endif @endforeach</span>
                            @endif
                        </td>
                        <td class="text-right tabular">{{ Qty::format($line->qty) }} {{ $line->saleItem->unit_snapshot }}</td>
                        <td>{!! $line->restock ? '<span class="text-brand-700">Back on the shelf</span>' : '<span class="text-red-700">Damaged, written off</span>' !!}</td>
                        <td class="text-right tabular">{{ $money($line->amount) }}</td>
                        @if ($showCost)<td class="text-right tabular text-gray-600">{{ $money($line->cost_total) }}</td>@endif
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Refund">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>Total</dt><dd class="text-lg font-semibold tabular">{{ $money($return->total) }}</dd></div>
                <div class="flex justify-between"><dt>Paid back as</dt><dd>{{ $return->refund_method->label() }}</dd></div>
                @if ($return->customer)<div class="flex justify-between"><dt>Customer</dt><dd><a href="{{ route('customers.show', $return->customer) }}" class="text-brand-700 hover:underline">{{ $return->customer->name }}</a></dd></div>@endif
                <div class="pt-2 text-gray-600">{{ $return->reason }}</div>
            </dl>
        </x-ui.card>
    </div>
@endsection
