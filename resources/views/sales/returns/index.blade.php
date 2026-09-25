@extends('layouts.app')

@section('title', 'Sale returns')

@section('content')
    <x-ui.page-header title="Sale returns" description="Goods brought back against invoices.">
        @if (auth()->user()->can('create', \App\Domain\Sales\Models\SaleReturn::class) && app(\App\Domain\Identity\Support\CurrentTerminal::class)->get()?->isMainCashier())
            <x-ui.button :href="route('pos.returns.create')">New return</x-ui.button>
        @endif
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('sales.returns.index')" placeholder="Return no. or reason" class="mb-4">
        <x-ui.select name="filter[refund_method]" :options="$methods" :value="request('filter.refund_method')" placeholder="Any refund" />
        <x-ui.date-input name="filter[date]" :value="request('filter.date')" />
    </x-ui.filter-bar>

    @if ($returns->isEmpty())
        <x-ui.empty-state title="No returns found" description="Returns are taken at the main cashier." />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="number">Return</x-ui.th-sortable>
                <x-ui.th-sortable column="created_at" default="created_at">Date</x-ui.th-sortable>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Refund</th>
                <th>Reason</th>
                <x-ui.th-sortable column="total">Amount</x-ui.th-sortable>
            </x-slot:head>
            @foreach ($returns as $return)
                <tr>
                    <td><a href="{{ route('sales.returns.show', $return) }}" class="font-mono text-brand-700 hover:underline">{{ $return->number }}</a></td>
                    <td class="text-gray-600">{{ $return->created_at->format('Y-m-d H:i') }}</td>
                    <td class="font-mono">{{ $return->sale->invoice_no }}</td>
                    <td>{{ $return->customer?->name ?? '—' }}</td>
                    <td>{{ $return->refund_method->label() }}</td>
                    <td class="text-gray-600">{{ $return->reason }}</td>
                    <td class="text-right tabular">{{ number_format((float) $return->total, 2) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$returns" />
    @endif
@endsection
