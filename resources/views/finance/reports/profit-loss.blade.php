@extends('layouts.app')

@php $money = fn ($value) => number_format((float) (string) $value, 2); @endphp

@section('title', 'Profit & loss')

@section('content')
    <x-ui.page-header title="Profit & loss" :description="$from->format('Y-m-d').' to '.$to->format('Y-m-d')">
        <x-ui.button variant="secondary" x-data x-on:click="window.print()" class="print:hidden">Print</x-ui.button>
    </x-ui.page-header>

    @include('finance.reports.tabs', ['active' => 'profit-loss'])
    @include('finance.partials.period', ['action' => route('finance.reports.profit-loss')])

    <x-ui.card class="max-w-3xl">
        <table class="w-full text-sm">
            <tr><td class="py-1">Sales</td><td class="text-right tabular">{{ $money($report['sales']) }}</td></tr>
            <tr><td class="py-1">Less: sales returns</td><td class="text-right tabular">({{ $money($report['returns']) }})</td></tr>
            <tr class="border-t border-gray-200 font-medium"><td class="py-1">Net sales</td><td class="text-right tabular">{{ $money($report['net_sales']) }}</td></tr>
            <tr><td class="py-1">Less: cost of goods sold</td><td class="text-right tabular">({{ $money($report['cogs']) }})</td></tr>
            <tr class="border-t border-gray-200 font-semibold"><td class="py-1.5">Gross profit</td><td class="text-right tabular">{{ $money($report['gross_profit']) }}</td></tr>

            @if ($report['income'])
                <tr><td colspan="2" class="pt-4 text-xs font-semibold uppercase text-gray-500">Other income</td></tr>
                @foreach ($report['income'] as $row)
                    <tr><td class="py-1 pl-4"><a href="{{ route('finance.accounts.show', ['account' => $row['account'], 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="hover:underline">{{ $row['account']->name }}</a></td><td class="text-right tabular">{{ $money($row['amount']) }}</td></tr>
                @endforeach
            @endif

            <tr><td colspan="2" class="pt-4 text-xs font-semibold uppercase text-gray-500">Expenses</td></tr>
            @forelse ($report['expenses'] as $row)
                <tr><td class="py-1 pl-4"><a href="{{ route('finance.accounts.show', ['account' => $row['account'], 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="hover:underline">{{ $row['account']->name }}</a></td><td class="text-right tabular">{{ $money($row['amount']) }}</td></tr>
            @empty
                <tr><td class="py-1 pl-4 text-gray-500">None</td><td></td></tr>
            @endforelse
            <tr class="border-t border-gray-200"><td class="py-1">Total expenses</td><td class="text-right tabular">({{ $money($report['total_expenses']) }})</td></tr>

            <tr class="border-t-2 border-gray-300 text-base font-semibold">
                <td class="py-2">{{ (float) $report['net_profit'] < 0 ? 'Net loss' : 'Net profit' }}</td>
                <td @class(['text-right tabular', 'text-red-700' => (float) $report['net_profit'] < 0])>{{ $money($report['net_profit']) }}</td>
            </tr>
        </table>
    </x-ui.card>
@endsection
