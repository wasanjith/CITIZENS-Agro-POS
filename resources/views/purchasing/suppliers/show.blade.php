@extends('layouts.app')

@section('title', $supplier->name)

@section('content')
    <x-ui.page-header :title="$supplier->name" :description="$supplier->is_active ? null : 'Inactive supplier'">
        @can('create', \App\Domain\Purchasing\Models\PurchaseOrder::class)
            <x-ui.button variant="secondary" :href="route('purchasing.purchase-orders.create', ['supplier_id' => $supplier->id])">New purchase order</x-ui.button>
        @endcan
        @can('create', \App\Domain\Purchasing\Models\GoodsReceipt::class)
            <x-ui.button variant="secondary" :href="route('purchasing.goods-receipts.create', ['supplier_id' => $supplier->id])">Receive goods</x-ui.button>
        @endcan
        @can('create', \App\Domain\Purchasing\Models\SupplierPayment::class)
            <x-ui.button variant="secondary" :href="route('purchasing.supplier-payments.create', ['supplier' => $supplier->id])">Pay supplier</x-ui.button>
        @endcan
        @can('update', $supplier)
            <x-ui.button :href="route('purchasing.suppliers.edit', $supplier)">Edit</x-ui.button>
        @endcan
        @can('delete', $supplier)
            <x-ui.button variant="secondary" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'delete-supplier')">Delete</x-ui.button>
            <x-ui.confirm-modal name="delete-supplier" :action="route('purchasing.suppliers.destroy', $supplier)" method="DELETE" title="Delete {{ $supplier->name }}?" confirm="Delete">
                Only suppliers without purchase orders or goods receipts can be deleted. Otherwise mark the supplier inactive.
            </x-ui.confirm-modal>
        @endcan
    </x-ui.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-tile label="Balance owed" :value="'Rs. '.number_format((float) (string) $balance, 2)" :hint="$supplier->payment_terms_days ? 'Terms: '.$supplier->payment_terms_days.' days' : 'Terms: cash'" />
        <x-ui.stat-tile label="Contact" :value="$supplier->contact_person ?: '—'" :hint="$supplier->phone" />
        <x-ui.stat-tile label="Opening balance" :value="'Rs. '.number_format((float) $supplier->opening_balance, 2)" :hint="$supplier->address" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Recent purchase orders">
            @if ($orders->isEmpty())
                <p class="text-sm text-gray-500">No purchase orders yet.</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach ($orders as $order)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ route('purchasing.purchase-orders.show', $order) }}" class="font-mono font-medium text-brand-700 hover:underline">{{ $order->number }}</a>
                            <span class="text-gray-500">{{ $order->order_date->format('Y-m-d') }}</span>
                            <x-ui.badge :color="$order->status->color()">{{ $order->status->label() }}</x-ui.badge>
                            <span class="tabular">{{ number_format((float) $order->total, 2) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="Recent goods receipts">
            @if ($receipts->isEmpty())
                <p class="text-sm text-gray-500">No goods received yet.</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach ($receipts as $receipt)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ route('purchasing.goods-receipts.show', $receipt) }}" class="font-mono font-medium text-brand-700 hover:underline">{{ $receipt->number }}</a>
                            <span class="text-gray-500">{{ $receipt->received_at->format('Y-m-d') }}</span>
                            <x-ui.badge :color="$receipt->status->color()">{{ $receipt->status->label() }}</x-ui.badge>
                            <span class="tabular">{{ number_format((float) $receipt->total, 2) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card title="Ledger" description="Credit = goods received (owed more). Debit = returns and payments (owed less)." class="mt-6">
        @if ($ledger->isEmpty())
            <p class="text-sm text-gray-500">No entries yet.</p>
        @else
            <x-ui.table class="shadow-none ring-0">
                <x-slot:head>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Reference</th>
                    <th>Note</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right">Credit</th>
                </x-slot:head>
                @foreach ($ledger as $entry)
                    <tr>
                        <td class="whitespace-nowrap text-gray-600">{{ $entry->date->format('Y-m-d') }}</td>
                        <td>{{ $entry->type->label() }}</td>
                        <td class="font-mono">{{ $entry->reference }}</td>
                        <td class="text-gray-600">{{ $entry->note ?: '—' }}</td>
                        <td class="text-right tabular">{{ (float) $entry->debit ? number_format((float) $entry->debit, 2) : '' }}</td>
                        <td class="text-right tabular">{{ (float) $entry->credit ? number_format((float) $entry->credit, 2) : '' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            <x-ui.pagination :paginator="$ledger" />
        @endif
    </x-ui.card>
@endsection
