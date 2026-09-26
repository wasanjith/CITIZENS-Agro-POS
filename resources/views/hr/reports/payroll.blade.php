@extends('layouts.app')

@section('title', 'Payroll summary '.$year)

@php $money = fn ($amount) => number_format((float) $amount, 2); @endphp

@section('content')
    <x-ui.page-header title="HR reports" :description="'Payroll summary · '.$year.' · approved months only'" />
    @include('hr.reports.tabs', ['active' => 'payroll'])

    <form method="GET" action="{{ route('hr.reports.payroll') }}" class="mb-4 flex items-end gap-3">
        <x-ui.input name="year" label="Year" type="number" :value="$year" min="2020" max="2100" class="w-28" />
        <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
    </form>

    @if ($runs->isEmpty())
        <x-ui.empty-state title="No approved payroll in {{ $year }}" />
    @else
        <h2 class="mb-2 text-base font-semibold">By month</h2>
        <x-ui.table class="mb-8">
            <x-slot:head>
                <th>Month</th>
                <th class="text-right">Gross</th>
                <th class="text-right">EPF 8 %</th>
                <th class="text-right">EPF 12 %</th>
                <th class="text-right">ETF 3 %</th>
                <th class="text-right">Net pay</th>
                <th>Status</th>
            </x-slot:head>
            @foreach ($runs as $run)
                <tr>
                    <td><a href="{{ route('hr.payroll.show', $run) }}" class="text-brand-700 hover:underline">{{ $run->label() }}</a></td>
                    <td class="text-right tabular">{{ $money($run->total_gross) }}</td>
                    <td class="text-right tabular">{{ $money($run->total_epf_employee) }}</td>
                    <td class="text-right tabular">{{ $money($run->total_epf_employer) }}</td>
                    <td class="text-right tabular">{{ $money($run->total_etf) }}</td>
                    <td class="text-right tabular font-medium">{{ $money($run->total_net) }}</td>
                    <td><x-ui.badge :color="$run->status->color()">{{ $run->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach
            <tr class="bg-gray-50 font-semibold">
                <td>Total</td>
                <td class="text-right tabular">{{ $money($runs->sum(fn ($run) => (float) $run->total_gross)) }}</td>
                <td class="text-right tabular">{{ $money($runs->sum(fn ($run) => (float) $run->total_epf_employee)) }}</td>
                <td class="text-right tabular">{{ $money($runs->sum(fn ($run) => (float) $run->total_epf_employer)) }}</td>
                <td class="text-right tabular">{{ $money($runs->sum(fn ($run) => (float) $run->total_etf)) }}</td>
                <td class="text-right tabular">{{ $money($runs->sum(fn ($run) => (float) $run->total_net)) }}</td>
                <td></td>
            </tr>
        </x-ui.table>

        <h2 class="mb-2 text-base font-semibold">By employee</h2>
        <x-ui.table>
            <x-slot:head>
                <th>Employee</th>
                <th class="text-right">Months</th>
                <th class="text-right">Gross</th>
                <th class="text-right">Overtime</th>
                <th class="text-right">No-pay</th>
                <th class="text-right">EPF 8 %</th>
                <th class="text-right">EPF 12 % + ETF</th>
                <th class="text-right">Advances</th>
                <th class="text-right">Net pay</th>
            </x-slot:head>
            @foreach ($byEmployee as $row)
                <tr>
                    <td class="font-medium">{{ $row->employee_name }}</td>
                    <td class="text-right tabular">{{ $row->months }}</td>
                    <td class="text-right tabular">{{ $money($row->gross) }}</td>
                    <td class="text-right tabular">{{ $money($row->ot) }}</td>
                    <td class="text-right tabular">{{ $money($row->no_pay) }}</td>
                    <td class="text-right tabular">{{ $money($row->epf_employee) }}</td>
                    <td class="text-right tabular">{{ $money((float) $row->epf_employer + (float) $row->etf) }}</td>
                    <td class="text-right tabular">{{ $money($row->advances) }}</td>
                    <td class="text-right tabular font-medium">{{ $money($row->net) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
@endsection
