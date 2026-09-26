@extends('layouts.app')

@php use App\Domain\Inventory\Support\Qty; @endphp

@section('title', $return->number)

@section('content')
    <x-ui.page-header :title="'Supplier return '.$return->number" :description="$return->supplier->name" />

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Goods returned" class="lg:col-span-2">
            <x-ui.table class="shadow-none ring-0">
                <x-slot:head>
                    <th>Product</th>
                    <th>Batch</th>
                    <th class="text-right">Quantity</th>
                    <th class="text-right">Buying price</th>
                    <th class="text-right">Value</th>
                </x-slot:head>
                @foreach ($return->lines as $line)
                    <tr>
                        <td>
                            <span class="font-mono text-xs font-semibold text-brand-700">{{ $line->variant?->short_code ?? $line->product->short_code }}</span>
                            <span class="font-medium">{{ $line->product->name }} {{ $line->variant?->name }}</span>
                        </td>
                        <td class="text-gray-600">{{ $line->batch->label() }}</td>
                        <td class="text-right tabular">{{ Qty::format($line->qty) }} {{ $line->product->baseUnit?->symbol }}</td>
                        <td class="text-right tabular">{{ number_format((float) $line->unit_cost, 2) }}</td>
                        <td class="text-right tabular">{{ number_format((float) $line->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            <p class="mt-4 text-right text-base font-semibold">Total: Rs. {{ number_format((float) $return->total, 2) }}</p>
        </x-ui.card>

        <x-ui.card title="Details">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-500">Supplier</dt><dd>{{ $return->supplier->name }}</dd></div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">From GRN</dt>
                    <dd>
                        @if ($return->goodsReceipt)
                            <a href="{{ route('purchasing.goods-receipts.show', $return->goodsReceipt) }}" class="font-mono text-brand-700 hover:underline">{{ $return->goodsReceipt->number }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">Date</dt><dd>{{ $return->return_date->format('Y-m-d') }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">By</dt><dd>{{ $return->creator?->name ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Reason</dt><dd class="mt-1">{{ $return->reason }}</dd></div>
            </dl>
        </x-ui.card>
    </div>
@endsection
