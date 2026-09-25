@extends('layouts.app')

@php
    $isNew = ! $order->exists;
    $inputClass = 'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500';
@endphp

@section('title', $isNew ? 'New purchase order' : 'Edit '.$order->number)

@section('content')
    <x-ui.page-header :title="$isNew ? 'New purchase order' : 'Edit purchase order '.$order->number" :description="$canCost ? null : 'Enter what to order. A Manager or the Owner adds the prices when approving.'" />

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

    @if ($order->exists && $order->rejected_reason)
        <x-ui.alert type="warning" title="Rejected" class="mb-4">{{ $order->rejected_reason }}</x-ui.alert>
    @endif

    <form
        method="POST"
        action="{{ $isNew ? route('purchasing.purchase-orders.store') : route('purchasing.purchase-orders.update', $order) }}"
        x-data="purchaseOrderForm({
            lines: @js($lines),
            canCost: @js($canCost),
            supplierId: @js(old('supplier_id', $order->supplier_id)),
            discount: @js((string) old('discount', $canCost ? $order->discount : '')),
            tax: @js((string) old('tax', $canCost ? $order->tax : '')),
            suggestionsUrl: @js(route('purchasing.purchase-orders.suggestions')),
        })"
        @product-picked="addLine($event.detail)"
        @search-select-changed="supplierId = $event.detail.id"
        class="space-y-6"
    >
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card title="Order">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.search-select
                    name="supplier_id"
                    label="Supplier"
                    :url="route('api.purchasing.suppliers')"
                    :value="$order->supplier_id"
                    :value-label="$supplierName"
                    placeholder="Type the supplier name"
                    required
                    class="sm:col-span-2"
                />
                <x-ui.date-input name="order_date" label="Order date" :value="$order->order_date" required />
                <x-ui.date-input name="expected_date" label="Expected delivery" :value="$order->expected_date" />
                <x-ui.textarea name="note" label="Note to supplier" :value="$order->note" rows="2" class="sm:col-span-2 lg:col-span-4" />
            </div>
        </x-ui.card>

        <x-ui.card title="Products">
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" x-on:click="suggest()" ::disabled="loading">Add products below reorder level</x-ui.button>
            </x-slot:actions>

            <x-ui.product-picker class="max-w-xl" />
            <p x-show="message" x-text="message" x-cloak class="mt-2 text-sm text-gray-600"></p>

            <div class="mt-4 overflow-x-auto" x-show="lines.length" x-cloak>
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                        <tr>
                            <th class="py-2 pr-3">Product</th>
                            <th class="py-2 pr-3 text-right">In stock</th>
                            <th class="py-2 pr-3">Unit</th>
                            <th class="py-2 pr-3">Quantity</th>
                            @if ($canCost)
                                <th class="py-2 pr-3">Unit cost</th>
                                <th class="py-2 pr-3 text-right">Total</th>
                            @endif
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
                                    <span class="block font-sinhala text-xs text-gray-500" x-text="line.name_si"></span>
                                </td>
                                <td class="py-2 pr-3 text-right tabular whitespace-nowrap">
                                    <span :class="Number(line.stock) <= Number(line.reorder_level) ? 'font-semibold text-amber-700' : ''" x-text="qtyText(line.stock) + ' ' + (line.base_unit ?? '')"></span>
                                    <span class="block text-xs text-gray-500" x-show="Number(line.reorder_level) > 0" x-text="'reorder at ' + qtyText(line.reorder_level)"></span>
                                </td>
                                <td class="py-2 pr-3">
                                    <select :name="`lines[${index}][unit_id]`" x-model="line.unit_id" class="{{ $inputClass }} w-28">
                                        <template x-for="unit in line.units" :key="unit.id">
                                            <option :value="unit.id" :selected="String(unit.id) === String(line.unit_id)" x-text="unit.factor > 1 ? `${unit.symbol} (${qtyText(unit.factor)} ${line.base_unit})` : unit.symbol"></option>
                                        </template>
                                    </select>
                                </td>
                                <td class="py-2 pr-3">
                                    <input type="number" :id="`qty-${line.key}`" :name="`lines[${index}][qty]`" x-model="line.qty" min="0" :step="unitOf(line)?.allows_decimal ? '0.001' : '1'" required class="{{ $inputClass }} w-28 tabular">
                                </td>
                                @if ($canCost)
                                    <td class="py-2 pr-3">
                                        <input type="text" inputmode="decimal" :name="`lines[${index}][unit_cost]`" x-model="line.unit_cost" placeholder="0.00" class="{{ $inputClass }} w-32 tabular">
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular whitespace-nowrap" x-text="money(lineTotal(line))"></td>
                                @endif
                                <td class="py-2 text-right">
                                    <button type="button" @click="removeLine(index)" class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove line">&times;</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p x-show="! lines.length" class="mt-4 text-sm text-gray-500">No products yet. Search above, or add the products that are below their reorder level.</p>

            @if ($canCost)
                <div class="mt-6 flex justify-end" x-show="lines.length" x-cloak>
                    <dl class="w-full max-w-xs space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">Subtotal</dt><dd class="tabular" x-text="money(subtotal())"></dd></div>
                        <div class="flex items-center justify-between gap-3">
                            <dt><label for="discount" class="text-gray-500">Discount</label></dt>
                            <dd><input type="text" inputmode="decimal" id="discount" name="discount" x-model="discount" placeholder="0.00" class="{{ $inputClass }} w-32 text-right tabular"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt><label for="tax" class="text-gray-500">Tax</label></dt>
                            <dd><input type="text" inputmode="decimal" id="tax" name="tax" x-model="tax" placeholder="0.00" class="{{ $inputClass }} w-32 text-right tabular"></dd>
                        </div>
                        <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold"><dt>Total</dt><dd class="tabular" x-text="money(total())"></dd></div>
                    </dl>
                </div>
            @endif

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="$isNew ? route('purchasing.purchase-orders.index') : route('purchasing.purchase-orders.show', $order)">Cancel</x-ui.button>
                <x-ui.button type="submit" name="action" value="save" variant="secondary">Save draft</x-ui.button>
                <x-ui.button type="submit" name="action" value="submit">Save &amp; submit for approval</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection

@push('scripts')
    <script>
        function purchaseOrderForm(config) {
            let nextKey = 1;

            const defaultUnitId = (line) => {
                const unit = line.units.find((unit) => unit.is_default_purchase) ?? line.units[0];
                return unit ? String(unit.id) : '';
            };

            const row = (line) => ({
                ...line,
                key: nextKey++,
                variant_id: line.variant_id ?? null,
                unit_id: line.unit_id ? String(line.unit_id) : defaultUnitId(line),
                qty: line.qty ?? '',
                unit_cost: line.unit_cost ?? '',
            });

            return {
                ...config,
                lines: config.lines.map(row),
                loading: false,
                message: '',

                addLine(item, focus = true) {
                    const existing = this.lines.find((line) => line.product_id === item.id && (line.variant_id ?? null) === (item.variant_id ?? null));
                    if (existing) {
                        this.message = `${item.name} is already on the order.`;
                        this.$nextTick(() => document.getElementById(`qty-${existing.key}`)?.focus());
                        return;
                    }

                    const line = row({
                        product_id: item.id,
                        variant_id: item.variant_id,
                        short_code: item.short_code,
                        name: item.name,
                        name_si: item.name_si,
                        units: item.units,
                        stock: item.stock,
                        base_unit: item.base_unit,
                        reorder_level: item.reorder_level,
                    });

                    if (this.canCost && item.reference_cost) {
                        line.unit_cost = (Number(item.reference_cost) * Number(this.unitOf(line)?.factor ?? 1)).toFixed(2);
                    }

                    this.lines.push(line);
                    this.message = '';

                    if (focus) {
                        this.$nextTick(() => document.getElementById(`qty-${line.key}`)?.focus());
                    }
                },

                removeLine(index) {
                    this.lines.splice(index, 1);
                },

                async suggest() {
                    const supplierId = this.supplierId || document.querySelector('input[name=supplier_id]')?.value;
                    if (!supplierId) {
                        this.message = 'Choose the supplier first.';
                        return;
                    }

                    this.loading = true;
                    try {
                        const response = await fetch(`${this.suggestionsUrl}?supplier_id=${encodeURIComponent(supplierId)}`, { headers: { Accept: 'application/json' } });
                        const data = await response.json();
                        let added = 0;
                        for (const line of data.lines) {
                            if (!this.lines.some((existing) => existing.product_id === line.product_id && (existing.variant_id ?? null) === (line.variant_id ?? null))) {
                                this.lines.push(row(line));
                                added++;
                            }
                        }
                        this.message = added ? `${added} product(s) added. Check the quantities.` : 'No more products are below their reorder level.';
                    } finally {
                        this.loading = false;
                    }
                },

                unitOf(line) {
                    return line.units.find((unit) => String(unit.id) === String(line.unit_id));
                },

                lineTotal(line) {
                    const qty = Number(line.qty), cost = Number(line.unit_cost);
                    return Number.isFinite(qty) && Number.isFinite(cost) ? Math.round(qty * cost * 100) / 100 : 0;
                },

                subtotal() {
                    return this.lines.reduce((sum, line) => sum + this.lineTotal(line), 0);
                },

                total() {
                    return this.subtotal() - (Number(this.discount) || 0) + (Number(this.tax) || 0);
                },

                money(amount) {
                    return Number(amount).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },

                qtyText(qty) {
                    return Number(qty ?? 0).toLocaleString('en-LK', { maximumFractionDigits: 3 });
                },
            };
        }
    </script>
@endpush
