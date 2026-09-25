@extends('layouts.app')

@php use App\Domain\Inventory\Support\Qty; @endphp

@section('title', $receipt->number)

@section('content')
    <x-ui.page-header :title="'Goods received '.$receipt->number" :description="$receipt->supplier->name">
        <x-ui.badge :color="$receipt->status->color()" class="text-sm">{{ $receipt->status->label() }}</x-ui.badge>
        @can('update', $receipt)
            <x-ui.button variant="secondary" :href="route('purchasing.goods-receipts.edit', $receipt)">Edit</x-ui.button>
        @endcan
        @can('cancel', $receipt)
            <x-ui.button variant="secondary" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'cancel-grn')">Cancel</x-ui.button>
            <x-ui.confirm-modal name="cancel-grn" :action="route('purchasing.goods-receipts.cancel', $receipt)" title="Cancel {{ $receipt->number }}?" confirm="Cancel GRN">
                Nothing has been added to stock yet. The draft stays on record as cancelled.
            </x-ui.confirm-modal>
        @endcan
        @can('post', $receipt)
            <x-ui.button x-data x-on:click="$dispatch('open-modal', 'post-grn')">Post to stock</x-ui.button>
            <x-ui.confirm-modal name="post-grn" :action="route('purchasing.goods-receipts.post', $receipt)" title="Post {{ $receipt->number }}?" confirm="Post" variant="primary">
                The goods are added to stock and Rs. {{ number_format((float) $receipt->total, 2) }} is added to what the shop owes {{ $receipt->supplier->name }}. A posted GRN cannot be changed; mistakes are corrected with a supplier return.
            </x-ui.confirm-modal>
        @endcan
        @if ($receipt->status === \App\Domain\Purchasing\Enums\GoodsReceiptStatus::Posted)
            @can('create', \App\Domain\Purchasing\Models\SupplierReturn::class)
                <x-ui.button variant="secondary" :href="route('purchasing.supplier-returns.create', ['goods_receipt' => $receipt->id])">Return goods</x-ui.button>
            @endcan
        @endif
    </x-ui.page-header>

    @foreach (['status', 'lines', 'stock'] as $field)
        @error($field)
            <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
        @enderror
    @endforeach
    @if ($errors->any() && ! $errors->hasAny(['status', 'lines', 'stock']))
        <x-ui.alert type="error" class="mb-4">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Goods" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                        <tr>
                            <th class="py-2 pr-3">Product</th>
                            <th class="py-2 pr-3 text-right">Qty</th>
                            <th class="py-2 pr-3 text-right">Free</th>
                            <th class="py-2 pr-3">Batch</th>
                            <th class="py-2 pr-3 text-right">Unit cost</th>
                            <th class="py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($receipt->lines as $line)
                            <tr class="align-top">
                                <td class="py-2 pr-3">
                                    <span class="font-mono text-xs font-semibold text-brand-700">{{ $line->variant?->short_code ?? $line->product->short_code }}</span>
                                    <span class="font-medium">{{ $line->product->name }} {{ $line->variant?->name }}</span>
                                    @unless ($line->po_line_id)
                                        <span class="block text-xs text-gray-500">{{ $receipt->purchase_order_id ? 'Not on the order' : '' }}</span>
                                    @endunless
                                </td>
                                <td class="py-2 pr-3 text-right tabular whitespace-nowrap">{{ Qty::format($line->qty) }} {{ $line->unit->symbol }}</td>
                                <td class="py-2 pr-3 text-right tabular">{{ (float) $line->free_qty ? Qty::format($line->free_qty) : '—' }}</td>
                                <td class="py-2 pr-3 text-gray-600">
                                    {{ $line->lot_no ? 'Lot '.$line->lot_no : '' }}
                                    @if ($line->expiry_date)<span class="block text-xs">exp {{ $line->expiry_date->format('Y-m-d') }}</span>@endif
                                    @if (! $line->lot_no && ! $line->expiry_date)—@endif
                                </td>
                                <td class="py-2 pr-3 text-right tabular">{{ number_format((float) $line->unit_cost, 2) }}</td>
                                <td class="py-2 text-right tabular">{{ number_format((float) $line->line_total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6 flex justify-end">
                <dl class="w-full max-w-xs space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Subtotal</dt><dd class="tabular">{{ number_format((float) $receipt->subtotal, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Discount</dt><dd class="tabular">{{ number_format((float) $receipt->discount, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Tax</dt><dd class="tabular">{{ number_format((float) $receipt->tax, 2) }}</dd></div>
                    <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold"><dt>Total owed</dt><dd class="tabular">Rs. {{ number_format((float) $receipt->total, 2) }}</dd></div>
                </dl>
            </div>
        </x-ui.card>

        <x-ui.card title="Details">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-500">Supplier</dt><dd class="text-right">{{ $receipt->supplier->name }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">Supplier invoice</dt><dd>{{ $receipt->supplier_invoice_no ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Purchase order</dt>
                    <dd>
                        @if ($receipt->purchaseOrder)
                            <a href="{{ route('purchasing.purchase-orders.show', $receipt->purchaseOrder) }}" class="font-mono text-brand-700 hover:underline">{{ $receipt->purchaseOrder->number }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">Received</dt><dd>{{ $receipt->received_at->format('Y-m-d H:i') }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">Received by</dt><dd>{{ $receipt->receiver?->name ?? '—' }}</dd></div>
                @if ($receipt->posted_at)
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Posted</dt><dd>{{ $receipt->posted_at->format('Y-m-d H:i') }}</dd></div>
                @endif
            </dl>
            @if ($receipt->note)
                <p class="mt-4 whitespace-pre-line border-t border-gray-200 pt-4 text-sm text-gray-700">{{ $receipt->note }}</p>
            @endif
        </x-ui.card>
    </div>
@endsection
