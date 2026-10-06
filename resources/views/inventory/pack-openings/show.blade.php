@extends('layouts.app')

@php
    use App\Domain\Inventory\Support\Qty;
    $showCost = auth()->user()->can('viewCost', \App\Domain\Catalog\Models\Product::class);
    $sealedUnit = $opening->sealedProduct->baseUnit?->symbol;
    $looseUnit = $opening->looseProduct->baseUnit?->symbol;
    $difference = $opening->difference();
@endphp

@section('title', $opening->number)

@section('content')
    <x-ui.page-header :title="'Opened packs '.$opening->number" :description="$opening->created_at?->format('Y-m-d H:i').' · '.($opening->creator?->name ?? 'System')" />

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Stock moved" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-gray-500">Opened</dt>
                    <dd class="mt-1">
                        <a href="{{ route('catalog.products.show', $opening->sealedProduct) }}" class="font-medium text-brand-700 hover:underline">{{ $opening->sealedProduct->name }}</a>
                        <span class="block text-lg font-semibold tabular text-red-700">−{{ Qty::format($opening->packs) }} {{ $sealedUnit }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500">Into loose stock</dt>
                    <dd class="mt-1">
                        <a href="{{ route('catalog.products.show', $opening->looseProduct) }}" class="font-medium text-brand-700 hover:underline">{{ $opening->looseProduct->name }}</a>
                        <span class="block text-lg font-semibold tabular text-brand-700">+{{ Qty::format($opening->weighed_qty) }} {{ $looseUnit }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500">Nominal weight</dt>
                    <dd class="tabular">{{ Qty::format($opening->expected_qty) }} {{ $looseUnit }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Difference</dt>
                    <dd @class(['tabular', 'text-red-700' => $difference->isNegative()])>
                        {{ $difference->isZero() ? 'None' : ($difference->isPositive() ? '+' : '').Qty::format($difference).' '.$looseUnit }}
                    </dd>
                </div>
                @if ($showCost)
                    <div>
                        <dt class="text-gray-500">Cost moved</dt>
                        <dd class="tabular">Rs. {{ number_format((float) $opening->cost_total, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Cost per {{ $looseUnit }}</dt>
                        <dd class="tabular">{{ (float) $opening->weighed_qty > 0 ? number_format((float) $opening->cost_total / (float) $opening->weighed_qty, 2) : '—' }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.card title="Details">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">From</dt>
                    <dd>
                        @if ($opening->goodsReceipt)
                            <a href="{{ route('purchasing.goods-receipts.show', $opening->goodsReceipt) }}" class="font-mono text-brand-700 hover:underline">{{ $opening->goodsReceipt->number }}</a>
                        @else
                            Shop stock
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">By</dt><dd>{{ $opening->creator?->name ?? '—' }}</dd></div>
            </dl>
            @if ($opening->note)
                <p class="mt-4 whitespace-pre-line border-t border-gray-200 pt-4 text-sm text-gray-700">{{ $opening->note }}</p>
            @endif
        </x-ui.card>
    </div>
@endsection
