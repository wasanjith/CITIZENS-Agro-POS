@extends('layouts.app')

@php $money = fn ($value) => number_format((float) (string) $value, 2); @endphp

@section('title', 'Trial balance')

@section('content')
    <x-ui.page-header title="Trial balance" :description="'As at '.$asOf->format('Y-m-d')">
        <x-ui.button variant="secondary" x-data x-on:click="window.print()" class="print:hidden">Print</x-ui.button>
    </x-ui.page-header>

    @include('finance.reports.tabs', ['active' => 'trial-balance'])

    <form method="GET" action="{{ route('finance.reports.trial-balance') }}" class="mb-4 flex items-end gap-3 print:hidden">
        <x-ui.date-input name="as_of" label="As at" :value="$asOf->toDateString()" />
        <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
    </form>

    @unless ($balanced)
        <x-ui.alert type="error" class="mb-4">Debits and credits differ. Please report this.</x-ui.alert>
    @endunless

    <x-ui.table class="max-w-4xl">
        <x-slot:head>
            <th>Code</th>
            <th>Account</th>
            <th class="text-right">Debit</th>
            <th class="text-right">Credit</th>
        </x-slot:head>
        @foreach ($rows as $row)
            <tr>
                <td class="font-mono">{{ $row['account']->code }}</td>
                <td><a href="{{ route('finance.accounts.show', $row['account']) }}" class="hover:underline">{{ $row['account']->name }}</a></td>
                <td class="text-right tabular">{{ (float) $row['debit'] ? $money($row['debit']) : '' }}</td>
                <td class="text-right tabular">{{ (float) $row['credit'] ? $money($row['credit']) : '' }}</td>
            </tr>
        @endforeach
        <tr class="bg-gray-50 font-semibold">
            <td colspan="2">Total</td>
            <td class="text-right tabular">{{ $money($debit) }}</td>
            <td class="text-right tabular">{{ $money($credit) }}</td>
        </tr>
    </x-ui.table>
@endsection
