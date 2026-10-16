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

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
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
                                <td class="py-2 pr-3 text-right tabular whitespace-nowrap">
                                    {{ Qty::format($line->qty) }} {{ $line->unit->symbol }}
                                    @if ((float) $line->open_packs > 0)
                                        <span class="block text-xs text-amber-800">{{ $receipt->posted_at ? 'Opened' : 'Open on posting' }}: {{ Qty::format($line->open_packs) }} {{ $line->product->baseUnit?->symbol }}</span>
                                    @endif
                                </td>
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

            @if ($receipt->packOpenings->isNotEmpty())
                <div class="mt-6 border-t border-gray-200 pt-4">
                    <p class="text-sm font-medium">Opened into loose stock</p>
                    <ul class="mt-2 space-y-1 text-sm text-gray-700">
                        @foreach ($receipt->packOpenings as $opening)
                            <li>
                                <a href="{{ route('inventory.pack-openings.show', $opening) }}" class="font-mono text-brand-700 hover:underline">{{ $opening->number }}</a>
                                · {{ Qty::format($opening->packs) }} opened → {{ $opening->looseProduct->name }} +{{ Qty::format($opening->weighed_qty) }} {{ $opening->looseProduct->baseUnit?->symbol }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-ui.card>

        {{-- On phones the details and invoice photos come first; on wide screens they sit on the right. --}}
        <div class="order-first space-y-6 lg:order-none">
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

            <x-ui.card title="Supplier invoice" id="supplier-invoice">
                @error('invoice_files')
                    <x-ui.alert type="error" class="mb-3">{{ $message }}</x-ui.alert>
                @enderror
                @if ($errors->has('invoice_files.*'))
                    <x-ui.alert type="error" class="mb-3">{{ $errors->first('invoice_files.*') }}</x-ui.alert>
                @endif

                @if ($receipt->invoices->isEmpty())
                    <p class="text-sm text-gray-500">No invoice photo yet.</p>
                @else
                    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-2">
                        @foreach ($receipt->invoices as $invoice)
                            @php $fileUrl = route('purchasing.goods-receipts.invoices.show', [$receipt, $invoice]); @endphp
                            <li class="relative">
                                <a href="{{ $fileUrl }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-md bg-gray-100 ring-1 ring-gray-200 hover:ring-brand-500" title="Open {{ $invoice->fileName() }}">
                                    @if ($invoice->isImage())
                                        <img src="{{ $fileUrl }}" alt="Invoice photo {{ $loop->iteration }}" loading="lazy" class="aspect-[3/4] w-full object-cover">
                                    @else
                                        <span class="flex aspect-[3/4] w-full flex-col items-center justify-center gap-1 p-2 text-center">
                                            <span class="rounded bg-red-600 px-1.5 py-0.5 text-xs font-bold text-white">PDF</span>
                                            <span class="break-all text-xs text-gray-600">{{ $invoice->original_name ?? $invoice->fileName() }}</span>
                                        </span>
                                    @endif
                                </a>
                                <p class="mt-1 truncate text-xs text-gray-500" title="{{ $invoice->fileName() }}">
                                    {{ $invoice->created_at?->format('Y-m-d H:i') }} · {{ $invoice->uploader?->name ?? '—' }}
                                </p>
                                @can('removeInvoice', $receipt)
                                    <button type="button" x-data x-on:click="$dispatch('open-modal', 'remove-invoice-{{ $invoice->id }}')"
                                            class="absolute right-1 top-1 flex size-8 items-center justify-center rounded-full bg-white/90 text-lg leading-none text-gray-700 shadow hover:bg-red-50 hover:text-red-700"
                                            aria-label="Remove invoice photo {{ $loop->iteration }}">&times;</button>
                                    <x-ui.confirm-modal name="remove-invoice-{{ $invoice->id }}" :action="route('purchasing.goods-receipts.invoices.destroy', [$receipt, $invoice])" method="DELETE" title="Remove this invoice photo?" confirm="Remove">
                                        The file {{ $invoice->fileName() }} is deleted from {{ $receipt->supplier->name }}'s invoice folder. The removal is kept in the audit log.
                                    </x-ui.confirm-modal>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif

                @can('attachInvoice', $receipt)
                    <form method="POST" action="{{ route('purchasing.goods-receipts.invoices.store', $receipt) }}" enctype="multipart/form-data" class="mt-4 border-t border-gray-200 pt-4">
                        @csrf
                        <x-purchasing.invoice-photos-input name="invoice_files">
                            <x-ui.button type="submit" class="w-full sm:w-auto">Save invoice photos</x-ui.button>
                        </x-purchasing.invoice-photos-input>
                    </form>
                @endcan
            </x-ui.card>
        </div>
    </div>
@endsection
