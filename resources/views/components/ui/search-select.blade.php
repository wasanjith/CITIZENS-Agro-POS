{{--
    Searchable select backed by a JSON endpoint returning [{ id, label, hint? }].
    <x-ui.search-select name="supplier_id" label="Supplier" :url="route('api.suppliers.search')" :value="$id" :value-label="$name" />
--}}
@props(['name', 'url', 'label' => null, 'value' => null, 'valueLabel' => '', 'placeholder' => 'Type to search…', 'required' => false])

@php
    $id = $attributes->get('id', str_replace(['[', ']', '.'], '_', $name));
@endphp

<div
    x-data="searchSelect({ url: @js($url), value: @js(old($name, $value)), label: @js($valueLabel) })"
    @click.outside="open = false"
    {{ $attributes->only('class')->merge(['class' => 'relative']) }}
>
    @if ($label)
        <x-ui.label :for="$id" :required="$required">{{ $label }}</x-ui.label>
    @endif

    <input type="hidden" name="{{ $name }}" :value="value">

    <div class="relative mt-1">
        <input
            type="text"
            id="{{ $id }}"
            x-model="query"
            @input.debounce.200ms="search()"
            @keydown.arrow-down.prevent="move(1)"
            @keydown.arrow-up.prevent="move(-1)"
            @keydown.enter.prevent="chooseHighlighted()"
            @keydown.escape="open = false"
            :placeholder="label || @js($placeholder)"
            autocomplete="off"
            class="block w-full rounded-md border-gray-300 pr-8 text-sm shadow-sm placeholder:text-gray-700 focus:border-brand-500 focus:ring-brand-500"
        >
        <button type="button" x-show="value" x-cloak @click="clear()" class="absolute inset-y-0 right-0 px-2 text-gray-400 hover:text-gray-600" aria-label="Clear">&times;</button>
    </div>

    <ul
        x-show="open && results.length"
        x-cloak
        class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md bg-white py-1 text-sm shadow-lg ring-1 ring-black/5"
    >
        <template x-for="(result, index) in results" :key="result.id">
            <li
                @click="choose(result)"
                @mouseenter="highlighted = index"
                :class="index === highlighted ? 'bg-brand-50 text-brand-900' : 'text-gray-800'"
                class="cursor-pointer px-3 py-2"
            >
                <span x-text="result.label"></span>
                <span x-show="result.hint" x-text="result.hint" class="ml-2 text-xs text-gray-500"></span>
            </li>
        </template>
    </ul>

    <x-ui.field-error :name="$name" />
</div>
