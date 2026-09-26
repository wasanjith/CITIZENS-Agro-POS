@extends('layouts.app')

@php $money = fn ($value) => number_format((float) (string) $value, 2); @endphp

@section('title', 'Balance sheet')

@section('content')
    <x-ui.page-header title="Balance sheet" :description="'As at '.$asOf->format('Y-m-d')">
        <x-ui.button variant="secondary" x-data x-on:click="window.print()" class="print:hidden">Print</x-ui.button>
    </x-ui.page-header>

    @include('finance.reports.tabs', ['active' => 'balance-sheet'])

    <form method="GET" action="{{ route('finance.reports.balance-sheet') }}" class="mb-4 flex items-end gap-3 print:hidden">
        <x-ui.date-input name="as_of" label="As at" :value="$asOf->toDateString()" />
        <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
    </form>

    @unless ($report['balanced'])
        <x-ui.alert type="error" class="mb-4">The balance sheet does not balance. Please report this.</x-ui.alert>
    @endunless

    <div class="grid max-w-5xl gap-6 lg:grid-cols-2">
        <x-ui.card title="Assets">
            <table class="w-full text-sm">
                @foreach ($report['assets'] as $row)
                    <tr><td class="py-1"><a href="{{ route('finance.accounts.show', $row['account']) }}" class="hover:underline">{{ $row['account']->name }}</a></td><td class="text-right tabular">{{ $money($row['amount']) }}</td></tr>
                @endforeach
                <tr class="border-t-2 border-gray-300 font-semibold"><td class="py-1.5">Total assets</td><td class="text-right tabular">{{ $money($report['total_assets']) }}</td></tr>
            </table>
        </x-ui.card>

        <x-ui.card title="Liabilities and equity">
            <table class="w-full text-sm">
                @foreach ($report['liabilities'] as $row)
                    <tr><td class="py-1"><a href="{{ route('finance.accounts.show', $row['account']) }}" class="hover:underline">{{ $row['account']->name }}</a></td><td class="text-right tabular">{{ $money($row['amount']) }}</td></tr>
                @endforeach
                <tr class="border-t border-gray-200 font-medium"><td class="py-1">Total liabilities</td><td class="text-right tabular">{{ $money($report['total_liabilities']) }}</td></tr>
                @foreach ($report['equity'] as $row)
                    <tr><td class="py-1"><a href="{{ route('finance.accounts.show', $row['account']) }}" class="hover:underline">{{ $row['account']->name }}</a></td><td class="text-right tabular">{{ $money($row['amount']) }}</td></tr>
                @endforeach
                <tr><td class="py-1">Profit to date</td><td class="text-right tabular">{{ $money($report['profit_to_date']) }}</td></tr>
                <tr class="border-t border-gray-200 font-medium"><td class="py-1">Total equity</td><td class="text-right tabular">{{ $money($report['total_equity']) }}</td></tr>
                <tr class="border-t-2 border-gray-300 font-semibold"><td class="py-1.5">Total liabilities and equity</td><td class="text-right tabular">{{ $money((float) $report['total_liabilities'] + (float) $report['total_equity']) }}</td></tr>
            </table>
        </x-ui.card>
    </div>
@endsection
