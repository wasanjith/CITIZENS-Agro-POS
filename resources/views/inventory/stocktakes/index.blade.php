@extends('layouts.app')

@section('title', 'Stocktakes')

@section('content')
    <x-ui.page-header title="Stocktakes" description="Count the shelves, review the differences, then post them to stock.">
        @can('create', \App\Domain\Inventory\Models\Stocktake::class)
            <x-ui.button :href="route('inventory.stocktakes.create')">Start a stocktake</x-ui.button>
        @endcan
    </x-ui.page-header>

    @if ($stocktakes->isEmpty())
        <x-ui.empty-state title="No stocktakes yet" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>Number</th>
                <th>Scope</th>
                <th>Started</th>
                <th>Counted</th>
                <th>Posted</th>
                <th>Status</th>
            </x-slot:head>
            @foreach ($stocktakes as $stocktake)
                <tr>
                    <td class="font-mono font-semibold">
                        <a href="{{ $stocktake->status === \App\Domain\Inventory\Enums\StocktakeStatus::Counting ? route('inventory.stocktakes.count', $stocktake) : route('inventory.stocktakes.show', $stocktake) }}" class="text-brand-700 hover:underline">{{ $stocktake->number }}</a>
                    </td>
                    <td>{{ $stocktake->scopeLabel() }}</td>
                    <td class="whitespace-nowrap text-gray-600">{{ $stocktake->created_at?->format('Y-m-d H:i') }}<br><span class="text-xs">{{ $stocktake->starter?->name }}</span></td>
                    <td class="tabular text-gray-600">{{ $stocktake->counted_count }} / {{ $stocktake->lines_count }}</td>
                    <td class="whitespace-nowrap text-gray-600">{{ $stocktake->posted_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td><x-ui.badge :color="$stocktake->status->color()">{{ $stocktake->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$stocktakes" />
    @endif
@endsection
