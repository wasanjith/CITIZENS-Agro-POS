@extends('layouts.app')

@php $inputClass = 'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500'; @endphp

@section('title', 'Return goods to supplier')

@section('content')
    <x-ui.page-header
        title="Return goods to supplier"
        :description="$receipt ? 'From '.$receipt->number.' · '.$receipt->supplier->name : 'Stock leaves the chosen batches and the supplier balance goes down by the buying price.'"
    />

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-4">
            <p class="font-medium">Please fix the following:</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form
        method="POST"
        action="{{ route('purchasing.supplier-returns.store') }}"
        x-data="batchLines({ lines: @js($lines), batchesUrl: @js(route('api.inventory.batches')) })"
        @product-picked="addLine($event.detail)"
        class="space-y-6"
    >
        @csrf

        <x-ui.card title="Return">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @if ($receipt)
                    <input type="hidden" name="goods_receipt_id" value="{{ $receipt->id }}">
                    <input type="hidden" name="supplier_id" value="{{ $receipt->supplier_id }}">
                    <div class="sm:col-span-2">
                        <p class="text-sm font-medium text-gray-700">Supplier</p>
                        <p class="mt-2 text-sm">{{ $receipt->supplier->name }} · <a href="{{ route('purchasing.goods-receipts.show', $receipt) }}" class="font-mono text-brand-700 hover:underline">{{ $receipt->number }}</a></p>
                    </div>
                @else
                    <x-ui.search-select name="supplier_id" label="Supplier" :url="route('api.purchasing.suppliers')" :value="$supplierId" :value-label="$supplierName" required class="sm:col-span-2" />
                @endif
                <x-ui.date-input name="return_date" label="Return date" :value="old('return_date', today()->toDateString())" required />
                <x-ui.input name="reason" label="Reason" :value="old('reason')" required placeholder="Damaged, expired, wrong item …" />
            </div>
        </x-ui.card>

        <x-ui.card title="Goods going back">
            <x-ui.product-picker class="max-w-xl" />
            <p x-show="message" x-text="message" x-cloak class="mt-2 text-sm text-amber-700"></p>

            <div class="mt-4 overflow-x-auto" x-show="lines.length" x-cloak>
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                        <tr>
                            <th class="py-2 pr-3">Product</th>
                            <th class="py-2 pr-3">Batch</th>
                            <th class="py-2 pr-3">Quantity</th>
                            <th class="py-2"><span class="sr-only">Remove</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(line, index) in lines" :key="line.key">
                            <tr class="align-top">
                                <td class="py-2 pr-3">
                                    <input type="hidden" :name="`lines[${index}][product_id]`" :value="line.product_id">
                                    <input type="hidden" :name="`lines[${index}][variant_id]`" :value="line.variant_id ?? ''">
                                    <span class="font-mono text-xs font-semibold text-brand-700" x-text="line.short_code"></span>
                                    <span class="font-medium" x-text="line.name"></span>
                                </td>
                                <td class="py-2 pr-3">
                                    <select :name="`lines[${index}][batch_id]`" x-model="line.batch_id" required class="{{ $inputClass }} w-72">
                                        <option value="">Choose batch…</option>
                                        <template x-for="batch in line.batches" :key="batch.id">
                                            <option :value="batch.id" :selected="String(batch.id) === String(line.batch_id)" x-text="`${batch.label} (${qtyText(batch.available ?? batch.on_hand)} ${line.base_unit ?? ''})`"></option>
                                        </template>
                                    </select>
                                </td>
                                <td class="py-2 pr-3">
                                    <div class="flex items-center gap-2">
                                        <input type="number" :id="`qty-${line.key}`" :name="`lines[${index}][qty]`" x-model="line.qty" min="0" step="any" required class="{{ $inputClass }} w-28 tabular">
                                        <span class="text-gray-500" x-text="line.base_unit"></span>
                                    </div>
                                </td>
                                <td class="py-2 text-right">
                                    <button type="button" @click="removeLine(index)" class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove line">&times;</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p x-show="! lines.length" class="mt-4 text-sm text-gray-500">Search for the products going back.</p>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="$receipt ? route('purchasing.goods-receipts.show', $receipt) : route('purchasing.supplier-returns.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">Save return</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
