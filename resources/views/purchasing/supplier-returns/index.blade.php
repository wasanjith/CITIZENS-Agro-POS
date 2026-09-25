@extends('layouts.app')

@section('title', 'Supplier returns')

@section('content')
    <x-ui.page-header title="Supplier returns" description="Goods sent back to suppliers.">
        @can('create', \App\Domain\Purchasing\Models\SupplierReturn::class)
            <x-ui.button :href="route('purchasing.supplier-returns.create')">Return goods</x-ui.button>
        @endcan
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('purchasing.supplier-returns.index')" placeholder="Number or reason" class="mb-4">
        <x-ui.select name="filter[supplier]" :options="$suppliers" :value="request('filter.supplier')" placeholder="All suppliers" />
    </x-ui.filter-bar>

    @if ($returns->isEmpty())
        <x-ui.empty-state title="No supplier returns found" />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="number" default="return_date">Number</x-ui.th-sortable>
                <th>Supplier</th>
                <th>GRN</th>
                <x-ui.th-sortable column="return_date" default="return_date">Date</x-ui.th-sortable>
                <th>Reason</th>
                <th>Lines</th>
                <x-ui.th-sortable column="total" default="return_date">Value</x-ui.th-sortable>
            </x-slot:head>

            @foreach ($returns as $return)
                <tr>
                    <td class="font-mono font-semibold"><a href="{{ route('purchasing.supplier-returns.show', $return) }}" class="text-brand-700 hover:underline">{{ $return->number }}</a></td>
                    <td>{{ $return->supplier->name }}</td>
                    <td class="font-mono text-gray-600">{{ $return->goodsReceipt?->number ?? '—' }}</td>
                    <td class="whitespace-nowrap text-gray-600">{{ $return->return_date->format('Y-m-d') }}</td>
                    <td class="text-gray-600">{{ $return->reason }}</td>
                    <td class="tabular text-gray-600">{{ $return->lines_count }}</td>
                    <td class="tabular">{{ number_format((float) $return->total, 2) }}</td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$returns" />
    @endif
@endsection
