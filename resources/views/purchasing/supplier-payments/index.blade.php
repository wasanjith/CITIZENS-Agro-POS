@extends('layouts.app')

@section('title', 'Supplier payments')

@section('content')
    <x-ui.page-header title="Supplier payments" description="Money paid to suppliers, applied to their goods receipts.">
        @can('create', \App\Domain\Purchasing\Models\SupplierPayment::class)
            <x-ui.button :href="route('purchasing.supplier-payments.create')">Pay a supplier</x-ui.button>
        @endcan
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('purchasing.supplier-payments.index')" placeholder="Payment no. or reference" class="mb-4">
        <x-ui.select name="filter[paid_from]" :options="$sources" :value="request('filter.paid_from')" placeholder="Any method" />
    </x-ui.filter-bar>

    @if ($payments->isEmpty())
        <x-ui.empty-state title="No payments found" />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="number">Payment</x-ui.th-sortable>
                <x-ui.th-sortable column="date" default="date">Date</x-ui.th-sortable>
                <th>Supplier</th>
                <th>Paid by</th>
                <th>Entered by</th>
                <x-ui.th-sortable column="amount" class="text-right">Amount</x-ui.th-sortable>
            </x-slot:head>
            @foreach ($payments as $payment)
                <tr @class(['text-gray-400 line-through' => $payment->reversed_at])>
                    <td><a href="{{ route('purchasing.supplier-payments.show', $payment) }}" class="font-mono text-brand-700 hover:underline">{{ $payment->number }}</a></td>
                    <td class="whitespace-nowrap">{{ $payment->date->format('Y-m-d') }}</td>
                    <td>{{ $payment->supplier->name }}</td>
                    <td>{{ $payment->paid_from->label() }} @if ($payment->reference)<span class="text-xs text-gray-500">· {{ $payment->reference }}</span>@endif</td>
                    <td class="text-gray-600">{{ $payment->createdBy->name }}</td>
                    <td class="text-right tabular">{{ number_format((float) $payment->amount, 2) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$payments" />
    @endif
@endsection
