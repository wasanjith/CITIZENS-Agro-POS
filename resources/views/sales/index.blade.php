@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
    <x-ui.page-header title="Invoices" description="Every numbered invoice, from printing at a counter to settlement at the cashier. Voided invoices keep their number.">
        @can('pos.live_view')
            <x-ui.button variant="secondary" :href="route('admin.live-billing')">Live Billing</x-ui.button>
        @endcan
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('sales.index')" placeholder="Invoice number" class="mb-4">
        <x-ui.select name="filter[status]" :options="$statuses" :value="request('filter.status')" placeholder="Any status" />
        <x-ui.select name="filter[terminal_id]" :options="$terminals->mapWithKeys(fn ($t) => [$t->id => $t->displayName()])->all()" :value="request('filter.terminal_id')" placeholder="Any counter" />
        <x-ui.date-input name="filter[date]" :value="request('filter.date')" />
    </x-ui.filter-bar>

    @if ($sales->isEmpty())
        <x-ui.empty-state title="No invoices found" />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="invoice_no" default="invoiced_at">Invoice</x-ui.th-sortable>
                <x-ui.th-sortable column="invoiced_at" default="invoiced_at">Printed</x-ui.th-sortable>
                <th>Counter · staff</th>
                <th>Method</th>
                <x-ui.th-sortable column="total" default="invoiced_at">Total</x-ui.th-sortable>
                <th>Settled</th>
                <th>Status</th>
            </x-slot:head>
            @foreach ($sales as $sale)
                <tr>
                    <td class="font-mono font-semibold"><a href="{{ route('sales.show', $sale) }}" class="text-brand-700 hover:underline">{{ $sale->invoice_no }}</a></td>
                    <td class="whitespace-nowrap text-gray-600">{{ $sale->invoiced_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $sale->invoicedTerminal->displayName() }} <span class="text-gray-500">· {{ $sale->invoicedBy->name }}</span></td>
                    <td>{{ $sale->payment_method_intent->label() }}</td>
                    <td class="tabular">{{ number_format((float) $sale->total, 2) }}</td>
                    <td class="whitespace-nowrap text-gray-600">{{ $sale->settled_at ? $sale->settled_at->format('H:i').' · '.$sale->settledBy?->name : '—' }}</td>
                    <td><x-ui.badge :color="$sale->status->color()">{{ $sale->status->label() }}</x-ui.badge> @if ($sale->print_count > 1)<span class="text-xs text-amber-700">{{ $sale->print_count }}× printed</span>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
        <div class="mt-4">{{ $sales->links() }}</div>
    @endif
@endsection
