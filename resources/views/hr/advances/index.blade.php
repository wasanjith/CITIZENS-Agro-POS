@extends('layouts.app')

@section('title', 'Salary advances')

@section('content')
    <x-ui.page-header title="Salary advances" :description="'Still owed by staff: Rs. '.number_format((float) $outstanding, 2).'. Installments come off the next payslips.'">
        <x-ui.button :href="route('hr.advances.create')">Give advance</x-ui.button>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('hr.advances.index')" placeholder="Number or note" class="mb-4">
        <x-ui.select name="filter[employee]" :options="$employees" :value="request('filter.employee')" placeholder="Everyone" />
        <x-ui.select name="filter[status]" :options="$statuses" :value="request('filter.status')" placeholder="Any status" />
    </x-ui.filter-bar>

    @if ($advances->isEmpty())
        <x-ui.empty-state title="No advances found" />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="number">Number</x-ui.th-sortable>
                <x-ui.th-sortable column="date" default="date">Date</x-ui.th-sortable>
                <th>Employee</th>
                <x-ui.th-sortable column="amount" class="text-right">Amount</x-ui.th-sortable>
                <th class="text-right">Installment</th>
                <th class="text-right">Recovered</th>
                <th class="text-right">Still owed</th>
                <th>Status</th>
            </x-slot:head>
            @foreach ($advances as $advance)
                <tr>
                    <td><a href="{{ route('hr.advances.show', $advance) }}" class="font-mono text-brand-700 hover:underline">{{ $advance->number }}</a></td>
                    <td class="whitespace-nowrap">{{ $advance->date->format('Y-m-d') }}</td>
                    <td>{{ $advance->employee->full_name }}</td>
                    <td class="text-right tabular">{{ number_format((float) $advance->amount, 2) }}</td>
                    <td class="text-right tabular">{{ number_format((float) $advance->installment_amount, 2) }} × {{ $advance->installments }}</td>
                    <td class="text-right tabular">{{ number_format((float) $advance->recovered_amount, 2) }}</td>
                    <td class="text-right tabular font-medium">{{ $advance->status === \App\Domain\HR\Enums\SalaryAdvanceStatus::Cancelled ? '—' : number_format((float) (string) $advance->outstanding(), 2) }}</td>
                    <td><x-ui.badge :color="$advance->status->color()">{{ $advance->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$advances" />
    @endif
@endsection
