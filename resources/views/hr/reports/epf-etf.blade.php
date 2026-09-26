@extends('layouts.app')

@section('title', 'EPF / ETF '.$start->format('F Y'))

@php $money = fn ($amount) => number_format((float) $amount, 2); @endphp

@section('content')
    <x-ui.page-header title="HR reports" :description="'EPF / ETF · '.$start->format('F Y').' · for the C form and the ETF return'" />
    @include('hr.reports.tabs', ['active' => 'epf-etf'])

    <form method="GET" action="{{ route('hr.reports.epf-etf') }}" class="mb-4 flex items-end gap-3">
        <x-ui.input name="month" label="Month" type="month" :value="$start->format('Y-m')" max="{{ today()->format('Y-m') }}" />
        <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
    </form>

    @if (! $run)
        <x-ui.empty-state title="No payroll for {{ $start->format('F Y') }}" />
    @else
        @if ($run->isEditable())
            <x-ui.alert type="warning" class="mb-4">This payroll is not approved yet; the amounts can still change.</x-ui.alert>
        @endif
        @if ($run->epf_etf_paid_at)
            <x-ui.alert type="success" class="mb-4">Paid to the funds on {{ $run->epf_etf_paid_at->format('Y-m-d') }}{{ $run->epf_etf_reference ? ' (ref. '.$run->epf_etf_reference.')' : '' }}.</x-ui.alert>
        @endif

        @if ($payslips->isEmpty())
            <x-ui.empty-state title="No EPF members in this payroll" />
        @else
            <x-ui.table>
                <x-slot:head>
                    <th>EPF No</th>
                    <th>Employee</th>
                    <th class="text-right">Total earnings</th>
                    <th class="text-right">Employee 8 %</th>
                    <th class="text-right">Employer 12 %</th>
                    <th class="text-right">EPF total</th>
                    <th class="text-right">ETF 3 %</th>
                </x-slot:head>
                @foreach ($payslips as $payslip)
                    <tr>
                        <td class="font-mono">{{ $payslip->epf_no ?: '—' }}</td>
                        <td>{{ $payslip->employee_name }}</td>
                        <td class="text-right tabular">{{ $money($payslip->epf_base) }}</td>
                        <td class="text-right tabular">{{ $money($payslip->epf_employee) }}</td>
                        <td class="text-right tabular">{{ $money($payslip->epf_employer) }}</td>
                        <td class="text-right tabular font-medium">{{ $money((float) $payslip->epf_employee + (float) $payslip->epf_employer) }}</td>
                        <td class="text-right tabular">{{ $money($payslip->etf) }}</td>
                    </tr>
                @endforeach
                <tr class="bg-gray-50 font-semibold">
                    <td colspan="2">Total ({{ $payslips->count() }})</td>
                    <td class="text-right tabular">{{ $money($payslips->sum(fn ($p) => (float) $p->epf_base)) }}</td>
                    <td class="text-right tabular">{{ $money($payslips->sum(fn ($p) => (float) $p->epf_employee)) }}</td>
                    <td class="text-right tabular">{{ $money($payslips->sum(fn ($p) => (float) $p->epf_employer)) }}</td>
                    <td class="text-right tabular">{{ $money($payslips->sum(fn ($p) => (float) $p->epf_employee + (float) $p->epf_employer)) }}</td>
                    <td class="text-right tabular">{{ $money($payslips->sum(fn ($p) => (float) $p->etf)) }}</td>
                </tr>
            </x-ui.table>
        @endif
    @endif
@endsection
