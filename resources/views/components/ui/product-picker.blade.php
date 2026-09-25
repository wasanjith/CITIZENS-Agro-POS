{{--
    Product search for line editors. Dispatches `product-picked` with the search item.
    <div x-data="…" @product-picked="addLine($event.detail)"><x-ui.product-picker /></div>
--}}
@props(['label' => 'Add product', 'placeholder' => 'Search by code, name, Sinhala name or alias (F2)'])

<div
    x-data="productPicker({ url: @js(route('api.pos.search')) })"
    @click.outside="open = false"
    {{ $attributes->merge(['class' => 'relative']) }}
>
    <label for="product-picker" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
    <input
        type="search"
        id="product-picker"
        x-ref="input"
        x-model="query"
        x-hotkey.f2="$el.focus(); $el.select()"
        @input.debounce.150ms="search()"
        @focus="items.length && (open = true)"
        @keydown.arrow-down.prevent="move(1)"
        @keydown.arrow-up.prevent="move(-1)"
        @keydown.enter.prevent="pick()"
        @keydown.escape="open = false"
        placeholder="{{ $placeholder }}"
        autocomplete="off"
        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"
    >
    <ul x-show="open" x-cloak class="absolute z-30 mt-1 max-h-80 w-full overflow-auto rounded-md bg-white py-1 text-sm shadow-lg ring-1 ring-black/5">
        <template x-for="(item, index) in items" :key="item.key">
            <li
                @click="pick(item)"
                @mouseenter="highlighted = index"
                :class="index === highlighted ? 'bg-brand-50' : ''"
                class="flex cursor-pointer items-start justify-between gap-3 px-3 py-2"
            >
                <span class="min-w-0">
                    <span class="font-mono text-xs font-semibold text-brand-700" x-text="item.short_code"></span>
                    <span class="font-medium text-gray-900" x-text="item.name"></span>
                    <span class="block truncate font-sinhala text-xs text-gray-500" x-text="item.name_si"></span>
                </span>
                <span class="whitespace-nowrap text-xs tabular text-gray-600" x-text="stockText(item)"></span>
            </li>
        </template>
        <li x-show="items.length === 0" class="px-3 py-2 text-gray-500">No products found.</li>
    </ul>
</div>
