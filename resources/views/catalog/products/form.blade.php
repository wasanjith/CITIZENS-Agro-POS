@extends('layouts.app')

@php
    $isNew = ! $product->exists;
    $canPrices = auth()->user()->can('managePrices', \App\Domain\Catalog\Models\Product::class);
    $canCost = auth()->user()->can('viewCost', \App\Domain\Catalog\Models\Product::class);

    $tabs = [
        'general' => ['label' => 'General', 'fields' => ['short_code', 'sku', 'name', 'category_id', 'brand_id', 'base_unit_id', 'tax_id', 'description']],
        'units' => ['label' => 'Units & Prices', 'fields' => ['units', 'prices', 'reference_cost', 'min_selling_margin_pct']],
        'variants' => ['label' => 'Variants', 'fields' => ['variants']],
        'names' => ['label' => 'Search & Names', 'fields' => ['name_si', 'name_ta', 'aliases', 'attribute_rows']],
        'stock' => ['label' => 'Stock settings', 'fields' => ['reorder_level', 'reorder_qty', 'track_batches', 'track_expiry']],
    ];
    $tabHasError = [];
    foreach ($tabs as $key => $tab) {
        $tabHasError[$key] = collect($errors->keys())->contains(fn ($error) => \Illuminate\Support\Str::startsWith($error, $tab['fields']));
    }
    $firstTab = array_key_first(array_filter($tabHasError)) ?? 'general';

    $inputClass = 'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500';
@endphp

@section('title', $isNew ? 'Add product' : 'Edit '.$product->name)

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add product' : 'Edit '.$product->short_code.' · '.$product->name" />

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
        action="{{ $isNew ? route('catalog.products.store') : route('catalog.products.update', $product) }}"
        x-data="productForm({
            tab: @js($firstTab),
            isNew: @js($isNew),
            code: @js(old('short_code', $product->short_code)),
            categoryId: @js((string) old('category_id', $product->category_id)),
            baseUnitId: @js((string) old('base_unit_id', $product->base_unit_id)),
            units: @js($units->map(fn ($unit) => ['id' => $unit->id, 'name' => $unit->name, 'symbol' => $unit->symbol])->values()),
            priceLists: @js($priceLists->map(fn ($list) => ['id' => $list->id, 'name' => $list->name])->values()),
            unitRows: @js($unitRows),
            prices: @js((object) $priceMatrix),
            variants: @js($variantRows),
            attributes: @js($attributeRows),
            cost: @js((string) old('reference_cost', $canCost ? $product->reference_cost : '')),
            margin: @js((string) old('min_selling_margin_pct', $canCost ? $product->min_selling_margin_pct : '')),
            nextCodeUrl: @js(route('catalog.products.next-code')),
        })"
    >
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <nav class="mb-4 flex gap-1 overflow-x-auto border-b border-gray-200" aria-label="Form sections">
            @foreach ($tabs as $key => $tab)
                <button
                    type="button"
                    x-on:click="tab = @js($key)"
                    :class="tab === @js($key) ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                    class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
                >
                    {{ $tab['label'] }}
                    @if ($tabHasError[$key])
                        <span class="ml-1 inline-block size-2 rounded-full bg-red-500" title="Has errors"></span>
                    @endif
                </button>
            @endforeach
        </nav>

        {{-- General --}}
        <div x-show="tab === 'general'">
            <x-ui.card>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-ui.label for="short_code" :required="true">Short code</x-ui.label>
                        <div class="mt-1 flex gap-2">
                            <input type="text" name="short_code" id="short_code" x-model="code" required maxlength="20" class="{{ $inputClass }} font-mono uppercase" autocomplete="off">
                            <x-ui.button variant="secondary" x-on:click="suggestCode(true)" x-bind:disabled="!categoryId" title="Next free code in the category range">Suggest</x-ui.button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500" x-text="rangeHint || 'The code staff type at the counter, e.g. 1023.'"></p>
                        <x-ui.field-error name="short_code" />
                    </div>
                    <x-ui.input name="sku" label="SKU / supplier code" :value="$product->sku" hint="Optional." />
                    <x-ui.input name="name" label="Name (English)" :value="$product->name" required class="sm:col-span-2" />
                    <x-ui.select name="category_id" label="Category" :options="$categories" placeholder="Choose…" required x-model="categoryId" x-on:change="suggestCode(false)" />
                    <x-ui.select name="brand_id" label="Brand" :options="$brands" :value="$product->brand_id" placeholder="No brand" />
                    <x-ui.select
                        name="base_unit_id"
                        label="Base unit"
                        :options="$units->mapWithKeys(fn ($unit) => [$unit->id => $unit->name])->all()"
                        placeholder="Choose…"
                        required
                        x-model="baseUnitId"
                        hint="Stock is counted in this unit. Other units are set on the Units & Prices tab."
                    />
                    <x-ui.select name="tax_id" label="Tax" :options="$taxes" :value="$product->tax_id" placeholder="No tax" />
                    <x-ui.textarea name="description" label="Description" :value="$product->description" class="sm:col-span-2" />
                    <x-ui.checkbox name="is_active" label="Active" hint="Inactive products are hidden from the POS search." :checked="$product->is_active" class="sm:col-span-2" />
                </div>
            </x-ui.card>
        </div>

        {{-- Units & Prices --}}
        <div x-show="tab === 'units'" x-cloak class="space-y-6">
            <x-ui.card title="Units" description="Units this product is sold or bought in, e.g. 1 bag = 50 kg.">
                <template x-if="!baseUnitId">
                    <p class="text-sm text-amber-700">Choose the base unit on the General tab first.</p>
                </template>

                <div x-show="baseUnitId" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                            <tr>
                                <th class="py-2 pr-3">Unit</th>
                                <th class="py-2 pr-3">Contains</th>
                                <th class="py-2 pr-3 text-center">Default sale</th>
                                <th class="py-2 pr-3 text-center">Default purchase</th>
                                <th class="py-2"><span class="sr-only">Remove</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr>
                                <td class="py-2 pr-3 font-medium" x-text="unitName(baseUnitId) + ' (base)'"></td>
                                <td class="py-2 pr-3 text-gray-500">1</td>
                                <td class="py-2 pr-3 text-center"><input type="radio" :checked="defaultSale === String(baseUnitId)" x-on:change="defaultSale = String(baseUnitId)" aria-label="Default sale unit" class="text-brand-600 focus:ring-brand-500"></td>
                                <td class="py-2 pr-3 text-center"><input type="radio" :checked="defaultPurchase === String(baseUnitId)" x-on:change="defaultPurchase = String(baseUnitId)" aria-label="Default purchase unit" class="text-brand-600 focus:ring-brand-500"></td>
                                <td></td>
                            </tr>
                            <template x-for="(row, index) in extraUnits" :key="row.key">
                                <tr>
                                    <td class="py-2 pr-3">
                                        <select x-model="row.unit_id" class="{{ $inputClass }} min-w-32" aria-label="Unit">
                                            <option value="">Choose…</option>
                                            <template x-for="unit in units.filter(u => String(u.id) !== String(baseUnitId))" :key="unit.id">
                                                <option :value="String(unit.id)" x-text="unit.name" :selected="String(unit.id) === String(row.unit_id)"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td class="py-2 pr-3">
                                        <div class="flex items-center gap-2">
                                            <span class="whitespace-nowrap text-gray-500" x-text="'1 ' + (unitName(row.unit_id) || 'unit') + ' ='"></span>
                                            <input type="number" x-model="row.factor" min="0.001" step="0.001" class="{{ $inputClass }} w-28" aria-label="Base units per unit">
                                            <span class="text-gray-500" x-text="unitName(baseUnitId)"></span>
                                        </div>
                                    </td>
                                    <td class="py-2 pr-3 text-center"><input type="radio" :checked="!!row.unit_id && defaultSale === String(row.unit_id)" x-on:change="defaultSale = String(row.unit_id)" :disabled="!row.unit_id" aria-label="Default sale unit" class="text-brand-600 focus:ring-brand-500"></td>
                                    <td class="py-2 pr-3 text-center"><input type="radio" :checked="!!row.unit_id && defaultPurchase === String(row.unit_id)" x-on:change="defaultPurchase = String(row.unit_id)" :disabled="!row.unit_id" aria-label="Default purchase unit" class="text-brand-600 focus:ring-brand-500"></td>
                                    <td class="py-2 text-right"><button type="button" x-on:click="removeUnit(index)" class="text-sm text-red-700 hover:underline">Remove</button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <x-ui.button variant="secondary" size="sm" class="mt-3" x-on:click="addUnit()">Add unit</x-ui.button>
                </div>

                {{-- Submitted unit rows --}}
                <template x-for="(row, index) in submittedUnits()" :key="'u' + index">
                    <span>
                        <input type="hidden" :name="`units[${index}][unit_id]`" :value="row.unit_id">
                        <input type="hidden" :name="`units[${index}][factor]`" :value="row.factor">
                        <input type="hidden" :name="`units[${index}][is_default_sale]`" :value="String(row.unit_id) === String(defaultSale) ? 1 : 0">
                        <input type="hidden" :name="`units[${index}][is_default_purchase]`" :value="String(row.unit_id) === String(defaultPurchase) ? 1 : 0">
                    </span>
                </template>
            </x-ui.card>

            @if ($canCost)
                <x-ui.card title="Cost and margin" description="Only visible to Owner and Manager.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-ui.label for="reference_cost">Cost per base unit</x-ui.label>
                            <div class="mt-1 flex rounded-md shadow-sm">
                                <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500">Rs.</span>
                                <input type="text" inputmode="decimal" name="reference_cost" id="reference_cost" x-model="cost" class="block w-full rounded-r-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Used for margin checks. Updated from goods receipts once inventory is live.</p>
                            <x-ui.field-error name="reference_cost" />
                        </div>
                        <div>
                            <x-ui.label for="min_selling_margin_pct">Minimum margin %</x-ui.label>
                            <input type="number" step="0.01" min="0" name="min_selling_margin_pct" id="min_selling_margin_pct" x-model="margin" class="{{ $inputClass }} mt-1">
                            <p class="mt-1 text-xs text-gray-500">Prices below cost + this margin are refused. Empty = no check.</p>
                            <x-ui.field-error name="min_selling_margin_pct" />
                        </div>
                    </div>
                </x-ui.card>
            @endif

            <x-ui.card title="Prices" :description="$canPrices ? 'Price of one unit on each price list. A change is saved as a new price; the old one stays in the history.' : 'You can view prices but not change them.'">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                            <tr>
                                <th class="py-2 pr-3">Unit</th>
                                <template x-for="list in priceLists" :key="list.id">
                                    <th class="py-2 pr-3" x-text="list.name"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template x-for="(row, rowIndex) in submittedUnits()" :key="'p' + row.unit_id">
                                <tr>
                                    <td class="py-2 pr-3 font-medium" x-text="unitName(row.unit_id)"></td>
                                    <template x-for="(list, listIndex) in priceLists" :key="list.id">
                                        <td class="py-2 pr-3 align-top">
                                            @if ($canPrices)
                                                <input type="hidden" :name="`prices[${rowIndex * priceLists.length + listIndex}][unit_id]`" :value="row.unit_id">
                                                <input type="hidden" :name="`prices[${rowIndex * priceLists.length + listIndex}][price_list_id]`" :value="list.id">
                                                <input
                                                    type="text"
                                                    inputmode="decimal"
                                                    :name="`prices[${rowIndex * priceLists.length + listIndex}][price]`"
                                                    x-model="prices[list.id + ':' + row.unit_id]"
                                                    class="{{ $inputClass }} w-32 text-right tabular"
                                                    :class="belowMinimum(list.id, row) ? 'border-red-400' : ''"
                                                    :aria-label="list.name + ' price per ' + unitName(row.unit_id)"
                                                    placeholder="0.00"
                                                >
                                            @else
                                                <span class="tabular" x-text="prices[list.id + ':' + row.unit_id] || '—'"></span>
                                            @endif
                                            @if ($canCost)
                                                <p class="mt-1 text-xs" :class="belowMinimum(list.id, row) ? 'text-red-700' : 'text-gray-500'" x-text="marginText(list.id, row)"></p>
                                            @endif
                                        </td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        {{-- Variants --}}
        <div x-show="tab === 'variants'" x-cloak>
            <x-ui.card title="Variants" description="Sizes or colours sold under this product, each with its own short code (e.g. Tyre 26&quot; and 28&quot;). Variants share the product's prices.">
                <template x-if="variants.length === 0">
                    <p class="text-sm text-gray-500">No variants. Most products don't need any.</p>
                </template>

                <div class="space-y-3">
                    <template x-for="(variant, index) in variants" :key="variant.key">
                        <div class="grid gap-3 rounded-md border border-gray-200 p-3 sm:grid-cols-12">
                            <input type="hidden" :name="`variants[${index}][id]`" :value="variant.id ?? ''" :disabled="!variant.id">
                            <div class="sm:col-span-2">
                                <label class="text-xs font-medium text-gray-600" :for="`variant_code_${index}`">Short code</label>
                                <input type="text" :id="`variant_code_${index}`" :name="`variants[${index}][short_code]`" x-model="variant.short_code" required maxlength="20" class="{{ $inputClass }} mt-1 font-mono uppercase">
                            </div>
                            <div class="sm:col-span-3">
                                <label class="text-xs font-medium text-gray-600" :for="`variant_name_${index}`">Name</label>
                                <input type="text" :id="`variant_name_${index}`" :name="`variants[${index}][name]`" x-model="variant.name" required maxlength="150" placeholder='26"' class="{{ $inputClass }} mt-1">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-medium text-gray-600" :for="`variant_size_${index}`">Size</label>
                                <input type="text" :id="`variant_size_${index}`" :name="`variants[${index}][size]`" x-model="variant.size" maxlength="40" class="{{ $inputClass }} mt-1">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-medium text-gray-600" :for="`variant_colour_${index}`">Colour</label>
                                <input type="text" :id="`variant_colour_${index}`" :name="`variants[${index}][colour]`" x-model="variant.colour" maxlength="40" class="{{ $inputClass }} mt-1">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-medium text-gray-600" :for="`variant_sku_${index}`">SKU</label>
                                <input type="text" :id="`variant_sku_${index}`" :name="`variants[${index}][sku]`" x-model="variant.sku" maxlength="50" class="{{ $inputClass }} mt-1">
                            </div>
                            <div class="flex items-end justify-between gap-2 sm:col-span-1 sm:flex-col sm:items-end">
                                <label class="flex items-center gap-1 text-xs text-gray-600">
                                    <input type="hidden" :name="`variants[${index}][is_active]`" :value="variant.is_active ? 1 : 0">
                                    <input type="checkbox" x-model="variant.is_active" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> Active
                                </label>
                                <button type="button" x-on:click="variants.splice(index, 1)" class="text-sm text-red-700 hover:underline">Remove</button>
                            </div>
                        </div>
                    </template>
                </div>

                <x-ui.button variant="secondary" size="sm" class="mt-3" x-on:click="addVariant()">Add variant</x-ui.button>
            </x-ui.card>
        </div>

        {{-- Search & Names --}}
        <div x-show="tab === 'names'" x-cloak>
            <x-ui.card title="Search & names" description="Everything staff might type to find this product.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="name_si" label="Sinhala name" :value="$product->name_si" class="font-sinhala" />
                    <x-ui.input name="name_ta" label="Tamil name" :value="$product->name_ta" />
                    <x-ui.textarea
                        name="aliases"
                        label="Aliases"
                        :value="$product->aliases"
                        hint="Singlish, slang and short names, separated by commas: yuriya, urea bag, u50"
                        class="sm:col-span-2"
                    />
                </div>

                <div class="mt-6">
                    <p class="text-sm font-medium text-gray-700">Attributes</p>
                    <p class="text-xs text-gray-500">Searchable details such as NPK 46-0-0, size 26", pack 1kg.</p>
                    <div class="mt-2 space-y-2">
                        <template x-for="(attribute, index) in attributes" :key="attribute.key_id">
                            <div class="flex gap-2">
                                <input type="text" :name="`attribute_rows[${index}][key]`" x-model="attribute.key" placeholder="NPK" maxlength="40" class="{{ $inputClass }} w-40" aria-label="Attribute name">
                                <input type="text" :name="`attribute_rows[${index}][value]`" x-model="attribute.value" placeholder="46-0-0" maxlength="100" class="{{ $inputClass }}" aria-label="Attribute value">
                                <button type="button" x-on:click="attributes.splice(index, 1)" class="px-2 text-sm text-red-700 hover:underline">Remove</button>
                            </div>
                        </template>
                    </div>
                    <x-ui.button variant="secondary" size="sm" class="mt-2" x-on:click="addAttribute()">Add attribute</x-ui.button>
                </div>
            </x-ui.card>
        </div>

        {{-- Stock settings --}}
        <div x-show="tab === 'stock'" x-cloak>
            <x-ui.card title="Stock settings">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.qty-input name="reorder_level" label="Reorder level" :value="$product->reorder_level" hint="Low-stock warning at this quantity (base units)." />
                    <x-ui.qty-input name="reorder_qty" label="Reorder quantity" :value="$product->reorder_qty" hint="Suggested order quantity (base units)." />
                    <x-ui.checkbox name="track_batches" label="Track batches / lot numbers" hint="For seeds and chemicals with lot numbers." :checked="$product->track_batches" />
                    <x-ui.checkbox name="track_expiry" label="Track expiry dates" hint="Oldest expiry is sold first." :checked="$product->track_expiry" />
                </div>
            </x-ui.card>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="$isNew ? route('catalog.products.index') : route('catalog.products.show', $product)">Cancel</x-ui.button>
            <x-ui.button type="submit">{{ $isNew ? 'Create product' : 'Save changes' }}</x-ui.button>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        function productForm(config) {
            let nextKey = 1;
            const key = () => nextKey++;

            const base = String(config.baseUnitId || '');
            const rows = config.unitRows.map((row) => ({ ...row, unit_id: String(row.unit_id) }));
            const bool = (value) => value === true || value === 1 || value === '1' || value === 'true';

            return {
                ...config,
                rangeHint: '',
                autoCode: config.isNew ? config.code : null,
                extraUnits: rows.filter((row) => row.unit_id !== base).map((row) => ({ key: key(), unit_id: row.unit_id, factor: row.factor })),
                defaultSale: String(rows.find((row) => bool(row.is_default_sale))?.unit_id ?? base),
                defaultPurchase: String(rows.find((row) => bool(row.is_default_purchase))?.unit_id ?? base),
                variants: config.variants.map((variant) => ({ ...variant, key: key(), is_active: bool(variant.is_active ?? true) })),
                attributes: config.attributes.map((attribute) => ({ ...attribute, key_id: key() })),

                init() {
                    this.$watch('baseUnitId', (value, old) => {
                        if (this.defaultSale === String(old)) this.defaultSale = String(value);
                        if (this.defaultPurchase === String(old)) this.defaultPurchase = String(value);
                        this.extraUnits = this.extraUnits.filter((row) => String(row.unit_id) !== String(value));
                    });
                },

                unitName(id) {
                    return this.units.find((unit) => String(unit.id) === String(id))?.name ?? '';
                },

                submittedUnits() {
                    if (!this.baseUnitId) return [];
                    const extras = this.extraUnits.filter((row) => row.unit_id && row.factor);
                    return [{ unit_id: String(this.baseUnitId), factor: '1' }, ...extras];
                },

                addUnit() {
                    this.extraUnits.push({ key: key(), unit_id: '', factor: '' });
                },

                removeUnit(index) {
                    const [row] = this.extraUnits.splice(index, 1);
                    if (this.defaultSale === String(row.unit_id)) this.defaultSale = String(this.baseUnitId);
                    if (this.defaultPurchase === String(row.unit_id)) this.defaultPurchase = String(this.baseUnitId);
                },

                addVariant() {
                    this.variants.push({ key: key(), id: null, short_code: '', name: '', size: '', colour: '', sku: '', is_active: true });
                },

                addAttribute() {
                    this.attributes.push({ key_id: key(), key: '', value: '' });
                },

                unitCost(row) {
                    const cost = parseFloat(this.cost);
                    return Number.isFinite(cost) && cost > 0 ? cost * parseFloat(row.factor || 0) : null;
                },

                belowMinimum(listId, row) {
                    const cost = this.unitCost(row);
                    const margin = parseFloat(this.margin);
                    const price = parseFloat(this.prices[listId + ':' + row.unit_id]);
                    if (cost === null || !Number.isFinite(margin) || !Number.isFinite(price)) return false;
                    return price < cost * (1 + margin / 100) - 0.004;
                },

                marginText(listId, row) {
                    const cost = this.unitCost(row);
                    const price = parseFloat(this.prices[listId + ':' + row.unit_id]);
                    if (cost === null) return '';
                    if (!Number.isFinite(price)) return 'Cost ' + cost.toFixed(2);
                    return 'Cost ' + cost.toFixed(2) + ' · margin ' + ((price - cost) / cost * 100).toFixed(1) + '%';
                },

                async suggestCode(force) {
                    if (!this.categoryId) return;
                    const response = await fetch(this.nextCodeUrl + '?category_id=' + encodeURIComponent(this.categoryId), { headers: { Accept: 'application/json' } });
                    if (!response.ok) return;
                    const data = await response.json();
                    this.rangeHint = data.range ? `Category range ${data.range[0]}–${data.range[1]}.` : 'This category has no code range.';
                    // Only replace a code the user has not typed themselves.
                    if (data.code && (force || (this.isNew && (!this.code || this.code === this.autoCode)))) {
                        this.code = data.code;
                        this.autoCode = data.code;
                    }
                },
            };
        }
    </script>
@endpush
