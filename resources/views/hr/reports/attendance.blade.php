@extends('layouts.app')

@section('title', 'Attendance summary')

@section('content')
    <x-ui.page-header title="HR reports" :description="'Attendance summary · '.$start->format('F Y').($start->isSameMonth(today()) ? ' (up to today)' : '')" />
    @include('hr.reports.tabs', ['active' => 'attendance'])

    <form method="GET" action="{{ route('hr.reports.attendance') }}" class="mb-4 flex items-end gap-3">
        <x-ui.input name="month" label="Month" type="month" :value="$start->format('Y-m')" max="{{ today()->format('Y-m') }}" />
        <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
    </form>

    @if ($rows->isEmpty())
        <x-ui.empty-state title="Nobody was employed this month" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>Employee</th>
                <th class="text-right">Working days</th>
                <th class="text-right">Worked</th>
                <th class="text-right">Paid leave</th>
                <th class="text-right">No-pay</th>
                <th class="text-right">Late (days)</th>
                <th class="text-right">Late (min)</th>
                <th class="text-right">Left early (min)</th>
                <th class="text-right">Overtime (h)</th>
                <th class="text-right">No clock-out</th>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr>
                    <td class="font-medium">{{ $row['employee']->full_name }}</td>
                    <td class="text-right tabular">{{ $row['days']['working'] }}</td>
                    <td class="text-right tabular">{{ $row['days']['worked'] }}</td>
                    <td class="text-right tabular">{{ $row['days']['paid_leave'] }}</td>
                    <td @class(['text-right tabular', 'font-semibold text-red-700' => (float) (string) $row['days']['no_pay'] > 0])>{{ $row['days']['no_pay'] }}</td>
                    <td class="text-right tabular">{{ (int) ($row['stats']->late_days ?? 0) }}</td>
                    <td class="text-right tabular">{{ (int) ($row['stats']->late_minutes ?? 0) }}</td>
                    <td class="text-right tabular">{{ (int) ($row['stats']->early_minutes ?? 0) }}</td>
                    <td class="text-right tabular">{{ round(((int) ($row['stats']->ot_minutes ?? 0)) / 60, 1) }}</td>
                    <td class="text-right tabular">{{ (int) ($row['stats']->missing_out ?? 0) ?: '' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
@endsection
