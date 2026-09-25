@extends('layouts.app')

@section('title', 'Stock adjustments')

@section('content')
    <x-ui.page-header title="Stock adjustments" description="Damage, expiry, loss and found goods.">
        @can('create', \App\Domain\Inventory\Models\StockAdjustment::class)
            <x-ui.button :href="route('inventory.adjustments.create')">Adjust stock</x-ui.button>
        @endcan
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('inventory.adjustments.index')" placeholder="Number or note" class="mb-4">
        <x-ui.select name="filter[status]" :options="$statuses" :value="request('filter.status')" placeholder="Any status" />
        <x-ui.select name="filter[reason]" :options="$reasons" :value="request('filter.reason')" placeholder="Any reason" />
    </x-ui.filter-bar>

    @if ($adjustments->isEmpty())
        <x-ui.empty-state title="No stock adjustments found" />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="number" default="created_at">Number</x-ui.th-sortable>
                <x-ui.th-sortable column="created_at" default="created_at">Date</x-ui.th-sortable>
                <th>Reason</th>
                <th>Lines</th>
                <x-ui.th-sortable column="total_value" default="created_at">Value</x-ui.th-sortable>
                <th>By</th>
                <th>Status</th>
            </x-slot:head>
            @foreach ($adjustments as $adjustment)
                <tr>
                    <td class="font-mono font-semibold"><a href="{{ route('inventory.adjustments.show', $adjustment) }}" class="text-brand-700 hover:underline">{{ $adjustment->number }}</a></td>
                    <td class="whitespace-nowrap text-gray-600">{{ $adjustment->created_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $adjustment->reason->label() }}</td>
                    <td class="tabular text-gray-600">{{ $adjustment->lines_count }}</td>
                    <td class="tabular">{{ number_format((float) $adjustment->total_value, 2) }}</td>
                    <td class="text-gray-600">{{ $adjustment->creator?->name ?? '—' }}</td>
                    <td><x-ui.badge :color="$adjustment->status->color()">{{ $adjustment->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$adjustments" />
    @endif
@endsection
