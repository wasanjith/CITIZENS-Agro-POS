@extends('layouts.app')

@php
    $isNew = ! $receipt->exists;
    $inputClass = 'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500';
@endphp

@section('title', $isNew ? 'Receive goods' : 'Edit '.$receipt->number)

@section('content')
    <x-ui.page-header
        :title="$isNew ? 'Receive goods' : 'Edit '.$receipt->number"
        :description="$order ? 'Against purchase order '.$order->number.' · '.$order->supplier->name : 'Direct delivery without a purchase order.'"
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
        action="{{ $isNew ? route('purchasing.goods-receipts.store') : route('purchasing.goods-receipts.update', $receipt) }}"
        x-data="goodsReceiptForm({
            lines: @js($lines),
            discount: @js((string) old('discount', (float) $receipt->discount ? $receipt->discount : '')),
            tax: @js((string) old('tax', (float) $receipt->tax ? $receipt->tax : '')),
        })"
        @product-picked="addLine($event.detail)"
        class="space-y-6"
    >
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card title="Delivery">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @if ($order)
                    <input type="hidden" name="purchase_order_id" value="{{ $order->id }}">
                    <input type="hidden" name="supplier_id" value="{{ $order->supplier_id }}">
                    <div class="sm:col-span-2">
                        <p class="text-sm font-medium text-gray-700">Supplier</p>
                        <p class="mt-2 text-sm">{{ $order->supplier->name }} · <a href="{{ route('purchasing.purchase-orders.show', $order) }}" class="font-mono text-brand-700 hover:underline">{{ $order->number }}</a></p>
                    </div>
                @else
                    <x-ui.search-select
                        name="supplier_id"
                        label="Supplier"
                        :url="route('api.purchasing.suppliers')"
                        :value="$receipt->supplier_id"
                        :value-label="$supplierName"
                        placeholder="Type the supplier name"
                        required
                        class="sm:col-span-2"
                    />
                @endif
                <x-ui.input name="supplier_invoice_no" label="Supplier invoice no." :value="$receipt->supplier_invoice_no" />
                <x-ui.input name="received_at" label="Received" type="datetime-local" :value="$receipt->received_at?->format('Y-m-d\TH:i')" required />
                <x-ui.textarea name="note" label="Note" :value="$receipt->note" rows="2" class="sm:col-span-2 lg:col-span-4" />
            </div>
        </x-ui.card>

        <x-ui.card title="Goods received" description="Quantities in the unit shown. Free quantity is extra stock the supplier gave at no charge; it lowers the cost per unit.">
            <x-ui.product-picker class="max-w-xl" label="Add a product that was not on the order" />
            <p x-show="message" x-text="message" x-cloak class="mt-2 text-sm text-gray-600"></p>

            <div class="mt-4 overflow-x-auto" x-show="lines.length" x-cloak>
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                        <tr>
                            <th class="py-2 pr-3">Product</th>
                            <th class="py-2 pr-3">Unit</th>
                            <th class="py-2 pr-3">Qty</th>
                            <th class="py-2 pr-3">Free</th>
                            <th class="py-2 pr-3">Unit cost</th>
                            <th class="py-2 pr-3">Lot no.</th>
                            <th class="py-2 pr-3">Expiry</th>
                            <th class="py-2 pr-3 text-right">Total</th>
                            <th class="py-2"><span class="sr-only">Remove</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(line, index) in lines" :key="line.key">
                            <tr class="align-top">
                                <td class="py-2 pr-3 min-w-48">
                                    <input type="hidden" :name="`lines[${index}][po_line_id]`" :value="line.po_line_id ?? ''">
                                    <input type="hidden" :name="`lines[${index}][product_id]`" :value="line.product_id">
                                    <input type="hidden" :name="`lines[${index}][variant_id]`" :value="line.variant_id ?? ''">
                                    <span class="font-mono text-xs font-semibold text-brand-700" x-text="line.short_code"></span>
                                    <span class="font-medium" x-text="line.name"></span>
                                    <span class="block text-xs text-gray-500" x-show="line.outstanding_base !== null && line.outstanding_base !== undefined" x-text="'Still to come: ' + qtyText(line.outstanding_base) + ' ' + line.base_unit"></span>
                                    <span class="block text-xs text-gray-500" x-show="! line.po_line_id" x-cloak>Not on the order</span>
                                </td>
                                <td class="py-2 pr-3">
                                    <select :name="`lines[${index}][unit_id]`" x-model="line.unit_id" class="{{ $inputClass }} w-24" :disabled="!! line.po_line_id">
                                        <template x-for="unit in line.units" :key="unit.id">
                                            <option :value="unit.id" :selected="String(unit.id) === String(line.unit_id)" x-text="unit.symbol"></option>
                                        </template>
                                    </select>
                                    {{-- A disabled select is not submitted: order lines keep the ordered unit. --}}
                                    <input type="hidden" :name="`lines[${index}][unit_id]`" :value="line.unit_id" :disabled="! line.po_line_id">
                                </td>
                                <td class="py-2 pr-3"><input type="number" :id="`qty-${line.key}`" :name="`lines[${index}][qty]`" x-model="line.qty" min="0" step="any" required class="{{ $inputClass }} w-24 tabular"></td>
                                <td class="py-2 pr-3"><input type="number" :name="`lines[${index}][free_qty]`" x-model="line.free_qty" min="0" step="any" class="{{ $inputClass }} w-20 tabular"></td>
                                <td class="py-2 pr-3"><input type="text" inputmode="decimal" :name="`lines[${index}][unit_cost]`" x-model="line.unit_cost" required placeholder="0.00" class="{{ $inputClass }} w-28 tabular"></td>
                                <td class="py-2 pr-3"><input type="text" :name="`lines[${index}][lot_no]`" x-model="line.lot_no" maxlength="50" class="{{ $inputClass }} w-28"></td>
                                <td class="py-2 pr-3">
                                    <input type="date" :name="`lines[${index}][expiry_date]`" x-model="line.expiry_date" :required="!! line.track_expiry" class="{{ $inputClass }} w-40">
                                    <input type="hidden" :name="`lines[${index}][mfg_date]`" :value="line.mfg_date ?? ''">
                                </td>
                                <td class="py-2 pr-3 text-right tabular whitespace-nowrap" x-text="money(lineTotal(line))"></td>
                                <td class="py-2 text-right">
                                    <button type="button" @click="lines.splice(index, 1)" class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove line">&times;</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p x-show="! lines.length" class="mt-4 text-sm text-gray-500">No goods yet. Search for the products that arrived.</p>

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
                    <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold"><dt>Total owed</dt><dd class="tabular" x-text="money(total())"></dd></div>
                </dl>
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="$isNew ? ($order ? route('purchasing.purchase-orders.show', $order) : route('purchasing.goods-receipts.index')) : route('purchasing.goods-receipts.show', $receipt)">Cancel</x-ui.button>
                <x-ui.button type="submit" name="action" value="save" variant="secondary">Save draft</x-ui.button>
                <x-ui.button type="submit" name="action" value="post">Save &amp; post to stock</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection

@push('scripts')
    <script>
        function goodsReceiptForm(config) {
            let nextKey = 1;

            const defaultUnitId = (line) => {
                const unit = line.units.find((unit) => unit.is_default_purchase) ?? line.units[0];
                return unit ? String(unit.id) : '';
            };

            const row = (line) => ({
                po_line_id: null,
                lot_no: '',
                expiry_date: '',
                mfg_date: '',
                free_qty: '',
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
                message: '',

                addLine(item) {
                    const line = row({
                        product_id: item.id,
                        variant_id: item.variant_id,
                        short_code: item.short_code,
                        name: item.name,
                        units: item.units,
                        base_unit: item.base_unit,
                        track_expiry: item.track_expiry,
                        outstanding_base: null,
                    });

                    if (item.reference_cost) {
                        const unit = line.units.find((unit) => String(unit.id) === line.unit_id);
                        line.unit_cost = (Number(item.reference_cost) * Number(unit?.factor ?? 1)).toFixed(2);
                    }

                    this.lines.push(line);
                    this.$nextTick(() => document.getElementById(`qty-${line.key}`)?.focus());
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
