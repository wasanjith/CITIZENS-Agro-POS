@extends('layouts.app')

@php $money = fn ($value) => number_format((float) $value, 2); @endphp

@section('title', 'Credit ageing')

@section('content')
    <x-ui.page-header title="Credit ageing" description="Unpaid credit invoices by how many days they are past their due date." />

    @if ($rows->isEmpty())
        <x-ui.empty-state title="Nobody owes anything" description="Credit invoices appear here once they are settled on a customer's account." />
    @else
        @php
            $totals = array_fill_keys(array_keys($labels), 0.0);
            foreach ($rows as $row) {
                foreach ($row['buckets'] as $key => $amount) {
                    $totals[$key] += (float) $amount;
                }
            }
        @endphp
        <x-ui.table>
            <x-slot:head>
                <th>Customer</th>
                @foreach ($labels as $label)
                    <th class="text-right">{{ $label }}</th>
                @endforeach
                <th class="text-right">Total</th>
            </x-slot:head>
            @foreach ($rows as $row)
                @php $customer = $customers[$row['customer_id']] ?? null; @endphp
                <tr>
                    <td>
                        @if ($customer)
                            <a href="{{ route('customers.show', $customer) }}" class="font-medium text-brand-700 hover:underline">{{ $customer->name }}</a>
                            <span class="block text-xs text-gray-500">{{ $customer->code }} · {{ $customer->phone }} {{ $customer->area }}</span>
                        @endif
                    </td>
                    @foreach ($labels as $key => $label)
                        <td @class(['text-right tabular', 'font-semibold text-red-700' => $key !== 'current' && (float) $row['buckets'][$key] > 0])>{{ (float) $row['buckets'][$key] > 0 ? $money($row['buckets'][$key]) : '' }}</td>
                    @endforeach
                    <td class="text-right font-semibold tabular">{{ $money($row['total']) }}</td>
                </tr>
            @endforeach
            <tr class="bg-gray-50 font-semibold">
                <td>Total</td>
                @foreach ($labels as $key => $label)
                    <td class="text-right tabular">{{ $money($totals[$key]) }}</td>
                @endforeach
                <td class="text-right tabular">{{ $money(array_sum($totals)) }}</td>
            </tr>
        </x-ui.table>
    @endif
@endsection
