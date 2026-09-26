@extends('layouts.app')

@section('title', 'Payroll')

@section('content')
    <x-ui.page-header title="Payroll" description="Calculate a month from the attendance, check each payslip, approve, then pay." />

    @error('month')<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror

    <x-ui.card class="mb-6">
        <form method="POST" action="{{ route('hr.payroll.store') }}" class="flex flex-wrap items-end gap-3" x-data="{ busy: false }" @submit="busy = true">
            @csrf
            <x-ui.input name="month" label="Month" type="month" :value="$nextMonth" max="{{ today()->format('Y-m') }}" required />
            <x-ui.button type="submit" x-bind:disabled="busy">Calculate payroll</x-ui.button>
        </form>
        <p class="mt-2 text-xs text-gray-500">Before calculating: correct the attendance (Attendance → Month view) so every working day has a record, and enter approved leave.</p>
    </x-ui.card>

    @if ($runs->isEmpty())
        <x-ui.empty-state title="No payroll yet" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>Month</th>
                <th>Status</th>
                <th class="text-right">Staff</th>
                <th class="text-right">Gross</th>
                <th class="text-right">Net pay</th>
                <th class="text-right">EPF + ETF</th>
                <th>EPF / ETF paid</th>
            </x-slot:head>
            @foreach ($runs as $run)
                <tr>
                    <td><a href="{{ route('hr.payroll.show', $run) }}" class="font-medium text-brand-700 hover:underline">{{ $run->label() }}</a></td>
                    <td><x-ui.badge :color="$run->status->color()">{{ $run->status->label() }}</x-ui.badge></td>
                    <td class="text-right tabular">{{ $run->payslips_count }}</td>
                    <td class="text-right tabular">{{ number_format((float) $run->total_gross, 2) }}</td>
                    <td class="text-right tabular font-medium">{{ number_format((float) $run->total_net, 2) }}</td>
                    <td class="text-right tabular">{{ number_format((float) $run->total_epf_employee + (float) $run->total_epf_employer + (float) $run->total_etf, 2) }}</td>
                    <td>{{ $run->epf_etf_paid_at?->format('Y-m-d') ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$runs" />
    @endif
@endsection
