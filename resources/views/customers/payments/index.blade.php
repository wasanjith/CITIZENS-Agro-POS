@extends('layouts.app')

@section('title', 'Customer payments')

@section('content')
    <x-ui.page-header title="Customer payments" description="Money customers paid towards their credit accounts.">
        @if (auth()->user()->can('create', \App\Domain\Customers\Models\CustomerPayment::class) && app(\App\Domain\Identity\Support\CurrentTerminal::class)->get()?->isMainCashier())
            <x-ui.button :href="route('pos.customer-payments.create')">Receive payment</x-ui.button>
        @endif
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('customers.payments.index')" placeholder="Receipt no. or reference" class="mb-4">
        <x-ui.select name="filter[method]" :options="$methods" :value="request('filter.method')" placeholder="Any method" />
        <x-ui.date-input name="filter[date]" :value="request('filter.date')" />
    </x-ui.filter-bar>

    @if ($payments->isEmpty())
        <x-ui.empty-state title="No payments found" description="Payments are taken at the main cashier." />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="number">Receipt</x-ui.th-sortable>
                <x-ui.th-sortable column="created_at" default="created_at">Date</x-ui.th-sortable>
                <th>Customer</th>
                <th>Method</th>
                <th>Received by</th>
                <x-ui.th-sortable column="amount" class="text-right">Amount</x-ui.th-sortable>
            </x-slot:head>
            @foreach ($payments as $payment)
                <tr>
                    <td><a href="{{ route('customers.payments.show', $payment) }}" class="font-mono text-brand-700 hover:underline">{{ $payment->number }}</a></td>
                    <td class="text-gray-600">{{ $payment->created_at->format('Y-m-d H:i') }}</td>
                    <td><a href="{{ route('customers.show', $payment->customer) }}" class="hover:underline">{{ $payment->customer->name }}</a></td>
                    <td>{{ $payment->method->label() }} @if ($payment->reference)<span class="text-xs text-gray-500">· {{ $payment->reference }}</span>@endif</td>
                    <td class="text-gray-600">{{ $payment->receivedBy->name }}</td>
                    <td class="text-right tabular">{{ number_format((float) $payment->amount, 2) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$payments" />
    @endif
@endsection
