@extends('layouts.app')

@php
    use App\Domain\Inventory\Enums\StocktakeStatus;
    use App\Domain\Inventory\Support\Qty;

    $needsApproval = $varianceValue->isGreaterThan((string) $approvalLimit) && ! auth()->user()->can('inventory.adjust.approve');
@endphp

@section('title', $stocktake->number)

@section('content')
    <x-ui.page-header :title="'Stocktake '.$stocktake->number" :description="$stocktake->scopeLabel()">
        <x-ui.badge :color="$stocktake->status->color()" class="text-sm">{{ $stocktake->status->label() }}</x-ui.badge>
        <x-ui.button variant="secondary" :href="route('inventory.stocktakes.sheet', $stocktake)" target="_blank">Count sheet</x-ui.button>
        @if ($stocktake->status === StocktakeStatus::Counting)
            <x-ui.button variant="secondary" :href="route('inventory.stocktakes.count', $stocktake)">Continue counting</x-ui.button>
        @endif
        @if ($stocktake->status === StocktakeStatus::Review)
            @can('review', $stocktake)
                <form method="POST" action="{{ route('inventory.stocktakes.reopen', $stocktake) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary">Back to counting</x-ui.button>
                </form>
            @endcan
        @endif
        @can('cancel', $stocktake)
            <x-ui.button variant="secondary" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'cancel-stocktake')">Cancel</x-ui.button>
            <x-ui.confirm-modal name="cancel-stocktake" :action="route('inventory.stocktakes.cancel', $stocktake)" title="Cancel {{ $stocktake->number }}?" confirm="Cancel stocktake">
                The counts are kept on record but stock does not change.
            </x-ui.confirm-modal>
        @endcan
        @can('post', $stocktake)
            <x-ui.button x-data x-on:click="$dispatch('open-modal', 'post-stocktake')" :disabled="$needsApproval">Post differences</x-ui.button>
            <x-ui.confirm-modal name="post-stocktake" :action="route('inventory.stocktakes.post', $stocktake)" title="Post {{ $stocktake->number }}?" confirm="Post" variant="primary">
                {{ $differenceCount }} {{ str('difference')->plural($differenceCount) }} will be written to stock. Lines that were not counted stay as they are.
            </x-ui.confirm-modal>
        @endcan
    </x-ui.page-header>

    @foreach (['status', 'stock'] as $field)
        @error($field)
            <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
        @enderror
    @endforeach

    @if ($stocktake->status === StocktakeStatus::Review && $needsApproval)
        <x-ui.alert type="warning" class="mb-4">The differences are worth more than Rs. {{ number_format((float) $approvalLimit, 2) }}. The Super Admin has to post this stocktake.</x-ui.alert>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-tile label="Counted" :value="$countedLines.' / '.$totalLines" hint="Lines not counted are left unchanged." />
        <x-ui.stat-tile label="Differences" :value="$differenceCount" />
        @if ($showCost)
            <x-ui.stat-tile label="Value of differences" :value="'Rs. '.number_format((float) (string) $varianceValue, 2)" hint="Shortages and surpluses added together." />
        @endif
    </div>

    <x-ui.card :title="$showAll ? 'All lines' : 'Differences'">
        <x-slot:actions>
            <x-ui.button variant="link" :href="route('inventory.stocktakes.show', [$stocktake, 'show' => $showAll ? null : 'all'])">{{ $showAll ? 'Only differences' : 'Show all lines' }}</x-ui.button>
        </x-slot:actions>

        @if ($lines->isEmpty())
            <p class="text-sm text-gray-500">{{ $showAll ? 'No lines.' : 'No differences: the count matches the system.' }}</p>
        @else
            <x-ui.table class="shadow-none ring-0">
                <x-slot:head>
                    <th>Product</th>
                    <th>Batch</th>
                    <th class="text-right">System</th>
                    <th class="text-right">Counted</th>
                    <th class="text-right">Difference</th>
                    @if ($showCost)
                        <th class="text-right">Value</th>
                    @endif
                    <th>Counted by</th>
                </x-slot:head>
                @foreach ($lines as $line)
                    @php $variance = $line->variance(); @endphp
                    <tr>
                        <td>
                            <span class="font-mono text-xs font-semibold text-brand-700">{{ $line->variant?->short_code ?? $line->product->short_code }}</span>
                            <span class="font-medium">{{ $line->product->name }} {{ $line->variant?->name }}</span>
                        </td>
                        <td class="text-gray-600">{{ $line->batch?->label() ?? 'General stock' }}</td>
                        <td class="text-right tabular">{{ Qty::format($line->system_qty) }}</td>
                        <td class="text-right tabular">{{ $line->counted_qty !== null ? Qty::format($line->counted_qty) : '—' }}</td>
                        <td @class(['text-right tabular font-medium', 'text-brand-700' => $variance?->isPositive(), 'text-red-700' => $variance?->isNegative()])>
                            {{ $variance === null ? '—' : ($variance->isPositive() ? '+' : '').Qty::format($variance) }} {{ $line->product->baseUnit?->symbol }}
                        </td>
                        @if ($showCost)
                            <td class="text-right tabular">{{ $variance === null ? '—' : number_format((float) (string) $variance->multipliedBy($line->unit_cost)->toScale(2, \Brick\Math\RoundingMode::HalfUp), 2) }}</td>
                        @endif
                        <td class="text-gray-600">{{ $line->counter?->name ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <p class="mt-4 text-xs text-gray-500">
        Started {{ $stocktake->created_at?->format('Y-m-d H:i') }} by {{ $stocktake->starter?->name ?? '—' }}.
        @if ($stocktake->posted_at)
            Posted {{ $stocktake->posted_at->format('Y-m-d H:i') }} by {{ $stocktake->poster?->name ?? '—' }}.
        @endif
    </p>
@endsection
