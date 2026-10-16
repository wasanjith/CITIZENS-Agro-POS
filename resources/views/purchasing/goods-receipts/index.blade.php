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
                    <td class="whitespace-nowrap text-gray-600">
                        {{ $receipt->supplier_invoice_no ?: '—' }}
                        @if ($receipt->invoices_count)
                            <a href="{{ route('purchasing.goods-receipts.show', $receipt) }}#supplier-invoice" class="ml-1 inline-flex items-center gap-0.5 rounded bg-brand-50 px-1.5 py-0.5 text-xs font-medium text-brand-700" title="{{ $receipt->invoices_count }} invoice {{ Str::plural('photo', $receipt->invoices_count) }}">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" /></svg>
                                {{ $receipt->invoices_count }}
                            </a>
                        @endif
                    </td>
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
