@extends('layouts.app')

@php use App\Domain\Inventory\Support\Qty; @endphp

@section('title', 'Count '.$stocktake->number)

@section('content')
    <x-ui.page-header :title="'Count '.$stocktake->number" :description="$stocktake->scopeLabel().' · enter what is on the shelf. Each count is saved as you go.'">
        <x-ui.button variant="secondary" :href="route('inventory.stocktakes.sheet', $stocktake)" target="_blank">Print count sheet</x-ui.button>
        @can('review', $stocktake)
            <form method="POST" action="{{ route('inventory.stocktakes.finish', $stocktake) }}">
                @csrf
                <x-ui.button type="submit">Finish counting</x-ui.button>
            </form>
        @endcan
    </x-ui.page-header>

    <div
        x-data="stocktakeCount({ url: @js(route('inventory.stocktakes.counts', $stocktake)), total: @js($lines->count()), counted: @js($lines->whereNotNull('counted_qty')->count()) })"
        class="space-y-4"
    >
        <div class="sticky top-16 z-20 -mx-4 flex flex-wrap items-center gap-3 border-b border-gray-200 bg-gray-50/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-lg sm:border">
            <input type="search" x-model="filter" x-hotkey.f2="$el.focus()" placeholder="Find product (F2)" class="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:w-72">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="onlyOpen" class="size-4 rounded border-gray-300 text-brand-600"> Only not counted</label>
            <span class="ml-auto text-sm text-gray-600"><span class="font-semibold tabular" x-text="counted"></span> of {{ $lines->count() }} counted</span>
            <span x-show="error" x-text="error" x-cloak class="w-full text-sm text-red-700"></span>
        </div>

        <ul class="divide-y divide-gray-100 rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            @foreach ($lines as $line)
                @php
                    $search = mb_strtolower(($line->variant?->short_code ?? $line->product->short_code).' '.$line->product->name.' '.$line->variant?->name.' '.$line->product->name_si.' '.$line->batch?->lot_no);
                @endphp
                <li
                    class="flex flex-wrap items-center gap-3 px-4 py-3"
                    x-data="{ value: @js($line->counted_qty !== null ? Qty::format($line->counted_qty) : ''), state: '' }"
                    x-show="matches(@js($search), value)"
                >
                    <div class="min-w-0 flex-1">
                        <span class="font-mono text-xs font-semibold text-brand-700">{{ $line->variant?->short_code ?? $line->product->short_code }}</span>
                        <span class="font-medium">{{ $line->product->name }} {{ $line->variant?->name }}</span>
                        <span class="block text-xs text-gray-500">
                            {{ $line->product->category?->name }}
                            @if ($line->batch && ! $line->batch->isDefault())
                                · {{ $line->batch->label() }}
                            @endif
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            inputmode="decimal"
                            min="0"
                            step="any"
                            x-model="value"
                            @change="state = 'saving'; state = await save({{ $line->id }}, value)"
                            @keydown.enter.prevent="$el.blur()"
                            class="block w-28 rounded-md border-gray-300 text-right text-lg tabular shadow-sm focus:border-brand-500 focus:ring-brand-500"
                            aria-label="Counted quantity of {{ $line->product->name }}"
                        >
                        <span class="w-10 text-sm text-gray-500">{{ $line->product->baseUnit?->symbol }}</span>
                        <span class="w-5 text-center" aria-live="polite">
                            <span x-show="state === 'saving'" x-cloak class="text-gray-400">…</span>
                            <span x-show="state === 'saved'" x-cloak class="text-brand-600">✓</span>
                            <span x-show="state === 'error'" x-cloak class="text-red-600">!</span>
                        </span>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endsection

@push('scripts')
    <script>
        function stocktakeCount({ url, counted }) {
            const token = document.querySelector('meta[name=csrf-token]')?.content;

            return {
                filter: '',
                onlyOpen: false,
                counted,
                error: '',

                matches(text, value) {
                    if (this.onlyOpen && value !== '' && value !== null) return false;
                    const filter = this.filter.trim().toLowerCase();
                    return filter === '' || filter.split(/\s+/).every((word) => text.includes(word));
                },

                async save(lineId, value) {
                    try {
                        const response = await fetch(url, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
                            body: JSON.stringify({ counts: { [lineId]: value === '' ? null : value } }),
                        });
                        if (!response.ok) {
                            const data = await response.json().catch(() => ({}));
                            this.error = data.message ?? 'Could not save. Check the connection and try again.';
                            return 'error';
                        }
                        this.error = '';
                        this.counted = document.querySelectorAll('[aria-label^="Counted quantity"]').length
                            - [...document.querySelectorAll('[aria-label^="Counted quantity"]')].filter((input) => input.value === '').length;
                        return 'saved';
                    } catch {
                        this.error = 'Could not save. Check the connection and try again.';
                        return 'error';
                    }
                },
            };
        }
    </script>
@endpush
