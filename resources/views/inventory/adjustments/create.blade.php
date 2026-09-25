@extends('layouts.app')

@php $inputClass = 'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500'; @endphp

@section('title', 'Adjust stock')

@section('content')
    <x-ui.page-header
        title="Adjust stock"
        :description="$canApproveAny
            ? 'Correct stock for damage, expiry, loss or found goods. It is posted straight away.'
            : 'Correct stock for damage, expiry, loss or found goods. Above Rs. '.number_format((float) $approvalLimit, 2).' it waits for the Super Admin\'s approval.'"
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
        action="{{ route('inventory.adjustments.store') }}"
        x-data="batchLines({ lines: @js($lines), batchesUrl: @js(route('api.inventory.batches')), allowGeneral: true })"
        @product-picked="addLine($event.detail)"
        class="space-y-6"
    >
        @csrf

        <x-ui.card title="Reason">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.select name="reason" label="Reason" :options="$reasons" :value="$reason" placeholder="Choose…" required />
                <x-ui.input name="note" label="Note" :value="old('note')" class="sm:col-span-2" placeholder="What happened?" />
            </div>
        </x-ui.card>

        <x-ui.card title="Products" description="Quantities are in each product's base unit.">
            <x-ui.product-picker class="max-w-xl" />

            <div class="mt-4 overflow-x-auto" x-show="lines.length" x-cloak>
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                        <tr>
                            <th class="py-2 pr-3">Product</th>
                            <th class="py-2 pr-3">Batch</th>
                            <th class="py-2 pr-3">Change</th>
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
                                    <input type="hidden" :name="`lines[${index}][qty]`" :value="signedQty(line)">
                                    <span class="font-mono text-xs font-semibold text-brand-700" x-text="line.short_code"></span>
                                    <span class="font-medium" x-text="line.name"></span>
                                </td>
                                <td class="py-2 pr-3">
                                    <select :name="`lines[${index}][batch_id]`" x-model="line.batch_id" class="{{ $inputClass }} w-72">
                                        <option value="" x-text="line.direction === '+' ? 'General stock' : 'Oldest / first to expire'"></option>
                                        <template x-for="batch in line.batches.filter((batch) => ! batch.is_default)" :key="batch.id">
                                            <option :value="batch.id" :selected="String(batch.id) === String(line.batch_id)" x-text="`${batch.label} (${qtyText(batch.on_hand)} ${line.base_unit ?? ''})`"></option>
                                        </template>
                                    </select>
                                </td>
                                <td class="py-2 pr-3">
                                    <select x-model="line.direction" class="{{ $inputClass }} w-36" aria-label="Add or remove">
                                        <option value="-">− Remove</option>
                                        <option value="+">+ Add</option>
                                    </select>
                                </td>
                                <td class="py-2 pr-3">
                                    <div class="flex items-center gap-2">
                                        <input type="number" :id="`qty-${line.key}`" x-model="line.qty" min="0" step="any" required class="{{ $inputClass }} w-28 tabular" aria-label="Quantity">
                                        <span class="text-gray-500" x-text="line.base_unit"></span>
                                    </div>
                                    <span class="text-xs text-gray-500" x-show="batchOf(line)" x-text="batchOf(line) ? 'Batch has ' + qtyText(batchOf(line).on_hand) : ''"></span>
                                </td>
                                <td class="py-2 text-right">
                                    <button type="button" @click="removeLine(index)" class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove line">&times;</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p x-show="! lines.length" class="mt-4 text-sm text-gray-500">Search for the products to adjust.</p>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('inventory.adjustments.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">Save adjustment</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
