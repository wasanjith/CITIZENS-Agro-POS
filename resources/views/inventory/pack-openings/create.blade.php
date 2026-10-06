@extends('layouts.app')

@php
    $inputClass = 'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500';
@endphp

@section('title', 'Open packs')

@section('content')
    <x-ui.page-header title="Open packs" description="Open sealed bags when the loose stock runs out. Both products' stock changes at once, and the bags' cost moves to the loose stock." />

    @error('stock')
        <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    @if ($packs === [])
        <x-ui.empty-state title="No product can be opened yet">
            On the sealed product (e.g. Urea 50kg bag), set “Can be opened into” to its loose product and the quantity in one pack.
        </x-ui.empty-state>
    @else
        <form
            method="POST"
            action="{{ route('inventory.pack-openings.store') }}"
            x-data="{
                packs: @js($packs),
                productId: @js((string) ($selected ?? '')),
                count: @js((string) old('packs', '')),
                selected() { return this.packs.find((pack) => String(pack.id) === this.productId) ?? null; },
                expected() {
                    const pack = this.selected(), count = Number(this.count) || 0;
                    return pack && count ? (count * Number(pack.per_pack)).toLocaleString('en-LK', { maximumFractionDigits: 3 }) : '';
                },
                qty(value) { return Number(value ?? 0).toLocaleString('en-LK', { maximumFractionDigits: 3 }); },
            }"
            class="max-w-2xl"
        >
            @csrf
            <x-ui.card>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-ui.label for="sealed_product_id" :required="true">Sealed pack</x-ui.label>
                        <select name="sealed_product_id" id="sealed_product_id" x-model="productId" required class="{{ $inputClass }} mt-1">
                            <option value="">Choose…</option>
                            <template x-for="pack in packs" :key="pack.id">
                                <option :value="pack.id" :selected="String(pack.id) === productId" x-text="pack.label"></option>
                            </template>
                        </select>
                        <p class="mt-1 text-xs text-gray-500" x-show="selected()" x-cloak>
                            <span x-text="qty(selected()?.available) + ' ' + selected()?.unit + ' in stock'"></span>
                            · opens into <span class="font-medium" x-text="selected()?.loose"></span>,
                            <span x-text="qty(selected()?.per_pack) + ' ' + selected()?.loose_unit"></span> per <span x-text="selected()?.unit"></span>
                        </p>
                        <x-ui.field-error name="sealed_product_id" />
                    </div>

                    <div>
                        <x-ui.label for="packs" :required="true">Packs to open</x-ui.label>
                        <input type="number" name="packs" id="packs" x-model="count" min="0" step="any" required class="{{ $inputClass }} mt-1 tabular">
                        <x-ui.field-error name="packs" />
                    </div>

                    <div>
                        <x-ui.label for="weighed_qty">Weighed into loose stock</x-ui.label>
                        <div class="mt-1 flex items-center gap-2">
                            <input type="number" name="weighed_qty" id="weighed_qty" value="{{ old('weighed_qty') }}" min="0" step="any" :placeholder="expected()" class="{{ $inputClass }} tabular">
                            <span class="text-sm text-gray-500" x-text="selected()?.loose_unit ?? ''"></span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Leave empty when the packs held their full weight.</p>
                        <x-ui.field-error name="weighed_qty" />
                    </div>

                    <x-ui.textarea name="note" label="Note" rows="2" class="sm:col-span-2" />
                </div>

                <x-slot:footer>
                    <x-ui.button variant="secondary" :href="route('inventory.pack-openings.index')">Cancel</x-ui.button>
                    <x-ui.button type="submit">Open packs</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        </form>
    @endif
@endsection
