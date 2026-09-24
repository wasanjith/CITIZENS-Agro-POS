@extends('layouts.app')

@php
    $canCost = auth()->user()->can('viewCost', \App\Domain\Catalog\Models\Product::class);
@endphp

@section('title', $product->short_code.' · '.$product->name)

@section('content')
    <x-ui.page-header :title="$product->name" :description="$product->category?->path()">
        @can('update', $product)
            <x-ui.button :href="route('catalog.products.edit', $product)">Edit</x-ui.button>
        @endcan
        @can('delete', $product)
            <x-ui.button variant="secondary" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'delete-product')">Delete</x-ui.button>
            <x-ui.confirm-modal
                name="delete-product"
                :action="route('catalog.products.destroy', $product)"
                method="DELETE"
                title="Delete {{ $product->name }}?"
                confirm="Delete"
            >
                The product disappears from lists and search. Old invoices keep showing it, and its short code {{ $product->short_code }} is never reused.
                To stop selling it for now, mark it inactive instead.
            </x-ui.confirm-modal>
        @endcan
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Details">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-gray-500">Short code</dt><dd class="font-mono text-lg font-semibold">{{ $product->short_code }}</dd></div>
                    <div><dt class="text-gray-500">Status</dt><dd><x-ui.badge :color="$product->is_active ? 'green' : 'red'">{{ $product->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></dd></div>
                    <div><dt class="text-gray-500">Sinhala name</dt><dd class="font-sinhala">{{ $product->name_si ?: '—' }}</dd></div>
                    <div><dt class="text-gray-500">Tamil name</dt><dd>{{ $product->name_ta ?: '—' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-gray-500">Aliases</dt><dd>{{ $product->aliases ?: '—' }}</dd></div>
                    <div><dt class="text-gray-500">Brand</dt><dd>{{ $product->brand?->name ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">SKU</dt><dd>{{ $product->sku ?: '—' }}</dd></div>
                    <div><dt class="text-gray-500">Base unit</dt><dd>{{ $product->baseUnit?->name }}</dd></div>
                    <div><dt class="text-gray-500">Tax</dt><dd>{{ $product->tax?->label() ?? 'No tax' }}</dd></div>
                    @if ($product->attributes)
                        <div class="sm:col-span-2">
                            <dt class="text-gray-500">Attributes</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">
                                @foreach ($product->attributes as $key => $value)
                                    <x-ui.badge>{{ $key }}: {{ $value }}</x-ui.badge>
                                @endforeach
                            </dd>
                        </div>
                    @endif
                    @if ($product->description)
                        <div class="sm:col-span-2"><dt class="text-gray-500">Description</dt><dd class="whitespace-pre-line">{{ $product->description }}</dd></div>
                    @endif
                </dl>
            </x-ui.card>

            <x-ui.card title="Units & current prices">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                            <tr>
                                <th class="py-2 pr-4">Unit</th>
                                @foreach ($priceLists as $list)
                                    <th class="py-2 pr-4 text-right">{{ $list->name }}</th>
                                @endforeach
                                @if ($canCost)
                                    <th class="py-2 text-right">Cost</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($product->units as $unit)
                                <tr>
                                    <td class="py-2 pr-4">
                                        <span class="font-medium">{{ $unit->unit->name }}</span>
                                        @if ($unit->unit_id !== $product->base_unit_id)
                                            <span class="text-xs text-gray-500">= {{ rtrim(rtrim($unit->factor, '0'), '.') }} {{ $product->baseUnit?->symbol }}</span>
                                        @endif
                                        @if ($unit->is_default_sale)
                                            <x-ui.badge color="green">sale</x-ui.badge>
                                        @endif
                                        @if ($unit->is_default_purchase)
                                            <x-ui.badge color="blue">purchase</x-ui.badge>
                                        @endif
                                    </td>
                                    @foreach ($priceLists as $list)
                                        <td class="py-2 pr-4 text-right tabular">
                                            @isset($prices[$list->id][$unit->unit_id])
                                                {{ number_format((float) $prices[$list->id][$unit->unit_id], 2) }}
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endisset
                                        </td>
                                    @endforeach
                                    @if ($canCost)
                                        <td class="py-2 text-right tabular text-gray-600">
                                            {{ $product->reference_cost !== null ? number_format((float) $product->reference_cost * (float) $unit->factor, 2) : '—' }}
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($canCost && $product->min_selling_margin_pct !== null)
                    <p class="mt-3 text-xs text-gray-500">Minimum margin: {{ $product->min_selling_margin_pct }}% over cost.</p>
                @endif
            </x-ui.card>

            @if ($product->variants->isNotEmpty())
                <x-ui.card title="Variants">
                    <x-ui.table class="shadow-none ring-0">
                        <x-slot:head>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Details</th>
                            <th>Status</th>
                        </x-slot:head>
                        @foreach ($product->variants as $variant)
                            <tr>
                                <td class="font-mono font-semibold">{{ $variant->short_code }}</td>
                                <td>{{ $variant->name }}</td>
                                <td class="text-gray-600">{{ collect($variant->attributes ?? [])->map(fn ($value, $key) => "{$key}: {$value}")->implode(', ') ?: '—' }}</td>
                                <td>
                                    @if ($variant->trashed())
                                        <x-ui.badge color="gray">Removed</x-ui.badge>
                                    @else
                                        <x-ui.badge :color="$variant->is_active ? 'green' : 'red'">{{ $variant->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif

            <x-ui.card title="Price history">
                @if ($priceHistory->isEmpty())
                    <p class="text-sm text-gray-500">No prices yet.</p>
                @else
                    <x-ui.table class="shadow-none ring-0">
                        <x-slot:head>
                            <th>From</th>
                            <th>Price list</th>
                            <th>Unit</th>
                            <th class="text-right">Price</th>
                            <th>By</th>
                        </x-slot:head>
                        @foreach ($priceHistory as $price)
                            <tr>
                                <td class="whitespace-nowrap text-gray-600">{{ $price->effective_from->format('Y-m-d H:i') }}</td>
                                <td>{{ $price->priceList->name }}</td>
                                <td>{{ $price->unit->name }}</td>
                                <td class="text-right tabular">{{ number_format((float) $price->price, 2) }}</td>
                                <td class="text-gray-600">{{ $price->creator?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Stock">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Reorder level</dt><dd class="tabular">{{ rtrim(rtrim($product->reorder_level, '0'), '.') ?: 0 }} {{ $product->baseUnit?->symbol }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Reorder quantity</dt><dd class="tabular">{{ rtrim(rtrim($product->reorder_qty, '0'), '.') ?: 0 }} {{ $product->baseUnit?->symbol }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Batches</dt><dd>{{ $product->track_batches ? 'Tracked' : 'No' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Expiry dates</dt><dd>{{ $product->track_expiry ? 'Tracked' : 'No' }}</dd></div>
                </dl>

                @if ($openingStock->isNotEmpty())
                    <div class="mt-4 border-t border-gray-200 pt-4">
                        <p class="text-sm font-medium">Opening stock (from import)</p>
                        <ul class="mt-2 space-y-1 text-sm text-gray-600">
                            @foreach ($openingStock as $entry)
                                <li>
                                    {{ rtrim(rtrim($entry->qty, '0'), '.') }} {{ $product->baseUnit?->symbol }}
                                    @if ($entry->lot_no) · lot {{ $entry->lot_no }} @endif
                                    @if ($entry->expiry_date) · exp. {{ $entry->expiry_date->format('Y-m-d') }} @endif
                                    @if ($canCost && $entry->unit_cost !== null) · cost {{ number_format((float) $entry->unit_cost, 2) }} @endif
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-gray-500">Added to stock when inventory goes live.</p>
                    </div>
                @endif

                <p class="mt-4 text-xs text-gray-500">Stock by batch and movement history appear here once inventory is set up.</p>
            </x-ui.card>

            <x-ui.card title="Recent changes">
                @if ($activity->isEmpty())
                    <p class="text-sm text-gray-500">No changes recorded.</p>
                @else
                    <ul class="space-y-3 text-sm">
                        @foreach ($activity as $entry)
                            <li>
                                <p><span class="font-medium">{{ $entry->causer?->name ?? 'System' }}</span> {{ $entry->description }}</p>
                                <p class="text-xs text-gray-500">{{ $entry->created_at?->format('Y-m-d H:i') }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="mt-3 text-xs text-gray-500">Created {{ $product->created_at?->format('Y-m-d') }}{{ $product->creator ? ' by '.$product->creator->name : '' }}.</p>
            </x-ui.card>
        </div>
    </div>
@endsection
