@extends('layouts.app')

@php $canCost = auth()->user()->can('viewCost', \App\Domain\Purchasing\Models\PurchaseOrder::class); @endphp

@section('title', 'Purchase orders')

@section('content')
    <x-ui.page-header title="Purchase orders" description="Anyone can raise an order; a Manager or the Owner fills in the costs and approves it.">
        @can('create', \App\Domain\Purchasing\Models\PurchaseOrder::class)
            <x-ui.button :href="route('purchasing.purchase-orders.create')">New purchase order</x-ui.button>
        @endcan
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('purchasing.purchase-orders.index')" placeholder="PO number" class="mb-4">
        <x-ui.select name="filter[status]" :options="$statuses" :value="request('filter.status')" placeholder="Any status" />
        <x-ui.select name="filter[supplier]" :options="$suppliers" :value="request('filter.supplier')" placeholder="All suppliers" />
        <x-ui.checkbox name="filter[mine]" label="Only mine" :checked="request()->boolean('filter.mine')" class="self-center" />
    </x-ui.filter-bar>

    @if ($orders->isEmpty())
        <x-ui.empty-state title="No purchase orders found">
            @can('create', \App\Domain\Purchasing\Models\PurchaseOrder::class)
                <x-ui.button :href="route('purchasing.purchase-orders.create')">New purchase order</x-ui.button>
            @endcan
        </x-ui.empty-state>
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="number" default="created_at">Number</x-ui.th-sortable>
                <th>Supplier</th>
                <x-ui.th-sortable column="order_date" default="created_at">Date</x-ui.th-sortable>
                <x-ui.th-sortable column="expected_date" default="created_at">Expected</x-ui.th-sortable>
                <th>Lines</th>
                <th>Raised by</th>
                @if ($canCost)
                    <th class="text-right">Total</th>
                @endif
                <th>Status</th>
            </x-slot:head>

            @foreach ($orders as $order)
                <tr>
                    <td class="font-mono font-semibold">
                        <a href="{{ route('purchasing.purchase-orders.show', $order) }}" class="text-brand-700 hover:underline">{{ $order->number }}</a>
                    </td>
                    <td>{{ $order->supplier->name }}</td>
                    <td class="whitespace-nowrap text-gray-600">{{ $order->order_date->format('Y-m-d') }}</td>
                    <td class="whitespace-nowrap text-gray-600">{{ $order->expected_date?->format('Y-m-d') ?? '—' }}</td>
                    <td class="tabular text-gray-600">{{ $order->lines_count }}</td>
                    <td class="text-gray-600">{{ $order->creator?->name ?? '—' }}</td>
                    @if ($canCost)
                        <td class="text-right tabular">{{ $order->approved_at || (float) $order->total ? number_format((float) $order->total, 2) : '—' }}</td>
                    @endif
                    <td><x-ui.badge :color="$order->status->color()">{{ $order->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$orders" />
    @endif
@endsection
