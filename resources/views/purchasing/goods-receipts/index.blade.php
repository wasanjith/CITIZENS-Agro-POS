@extends('layouts.app')

@section('title', 'Goods received')

@section('content')
    <x-ui.page-header title="Goods received (GRN)" description="Deliveries from suppliers. Posting a GRN adds the stock and what is owed to the supplier.">
        @can('create', \App\Domain\Purchasing\Models\GoodsReceipt::class)
            <x-ui.button :href="route('purchasing.goods-receipts.create')">Receive without a PO</x-ui.button>
        @endcan
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('purchasing.goods-receipts.index')" placeholder="GRN or supplier invoice no." class="mb-4">
        <x-ui.select name="filter[status]" :options="$statuses" :value="request('filter.status')" placeholder="Any status" />
        <x-ui.select name="filter[supplier]" :options="$suppliers" :value="request('filter.supplier')" placeholder="All suppliers" />
    </x-ui.filter-bar>

    @if ($receipts->isEmpty())
        <x-ui.empty-state title="No goods receipts found" description="Receive goods from an approved purchase order, or directly." />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="number" default="received_at">Number</x-ui.th-sortable>
                <th>Supplier</th>
                <th>Supplier invoice</th>
                <th>PO</th>
                <x-ui.th-sortable column="received_at" default="received_at">Received</x-ui.th-sortable>
                <x-ui.th-sortable column="total" default="received_at" class="text-right">Total</x-ui.th-sortable>
                <th>Status</th>
            </x-slot:head>

            @foreach ($receipts as $receipt)
                <tr>
                    <td class="font-mono font-semibold"><a href="{{ route('purchasing.goods-receipts.show', $receipt) }}" class="text-brand-700 hover:underline">{{ $receipt->number }}</a></td>
                    <td>{{ $receipt->supplier->name }}</td>
                    <td class="text-gray-600">{{ $receipt->supplier_invoice_no ?: '—' }}</td>
                    <td class="font-mono text-gray-600">{{ $receipt->purchaseOrder?->number ?? '—' }}</td>
                    <td class="whitespace-nowrap text-gray-600">{{ $receipt->received_at->format('Y-m-d H:i') }}</td>
                    <td class="text-right tabular">{{ number_format((float) $receipt->total, 2) }}</td>
                    <td><x-ui.badge :color="$receipt->status->color()">{{ $receipt->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$receipts" />
    @endif
@endsection
