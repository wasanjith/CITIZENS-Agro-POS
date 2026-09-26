@extends('layouts.app')

@section('title', 'Attendance '.$date->format('Y-m-d'))

@php
    $minutes = fn (int $value) => $value > 0 ? intdiv($value, 60).'h '.str_pad((string) ($value % 60), 2, '0', STR_PAD_LEFT).'m' : '';
@endphp

@section('content')
    <x-ui.page-header title="Attendance" :description="$date->format('l, j F Y').($holiday ? ' · Holiday: '.$holiday : '')">
        <x-ui.button variant="secondary" :href="route('hr.attendance.index', ['date' => $date->copy()->subDay()->toDateString()])">&larr; Previous day</x-ui.button>
        @if ($date->lt(today()))
            <x-ui.button variant="secondary" :href="route('hr.attendance.index', ['date' => $date->copy()->addDay()->toDateString()])">Next day &rarr;</x-ui.button>
        @endif
        <x-ui.button :href="route('hr.attendance.month', ['month' => $date->format('Y-m')])">Month view</x-ui.button>
    </x-ui.page-header>

    @foreach (['status', 'clock_in', 'clock_out', 'edit_reason', 'date'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    <form method="GET" action="{{ route('hr.attendance.index') }}" class="mb-4 flex items-end gap-3">
        <x-ui.date-input name="date" label="Day" :value="$date" max="{{ today()->toDateString() }}" />
        <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
    </form>

    @if ($employees->isEmpty())
        <x-ui.empty-state title="Nobody was employed on this day" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>Employee</th>
                <th>Status</th>
                <th>In</th>
                <th>Out</th>
                <th class="text-right">Late</th>
                <th class="text-right">Left early</th>
                <th class="text-right">Overtime</th>
                <th>Note</th>
                <th></th>
            </x-slot:head>
            @foreach ($employees as $employee)
                @php $row = $rows->get($employee->id); $working = $isWorkingDay($employee); @endphp
                <tr>
                    <td>
                        <span class="font-medium">{{ $employee->full_name }}</span>
                        <span class="block text-xs text-gray-500">{{ $employee->workingShift()?->hoursLabel() }}</span>
                    </td>
                    <td>
                        @if ($row)
                            <x-ui.badge :color="$row->status->color()">{{ $row->status->label() }}</x-ui.badge>
                            @if ($row->leaveRequest)<span class="block text-xs text-gray-500">{{ $row->leaveRequest->leaveType->name }}</span>@endif
                        @elseif (! $working)
                            <span class="text-xs text-gray-500">Day off</span>
                        @elseif ($date->isToday())
                            <span class="text-xs text-gray-500">Not in yet</span>
                        @else
                            <x-ui.badge color="red">No record</x-ui.badge>
                        @endif
                    </td>
                    <td class="tabular">
                        {{ $row?->clock_in?->format('H:i') }}
                        @if ($row?->photo_path)
                            <a href="{{ route('hr.attendance.photo', $row) }}" target="_blank" title="Photo taken at clock-in">📷</a>
                        @endif
                    </td>
                    <td class="tabular">
                        {{ $row?->clock_out?->format('H:i') }}
                        @if ($row?->missingClockOut())<x-ui.badge color="amber">No clock-out</x-ui.badge>@endif
                    </td>
                    <td class="text-right tabular text-amber-700">{{ $minutes($row->late_minutes ?? 0) }}</td>
                    <td class="text-right tabular text-amber-700">{{ $minutes($row->early_leave_minutes ?? 0) }}</td>
                    <td class="text-right tabular text-brand-700">{{ $minutes($row->ot_minutes ?? 0) }}</td>
                    <td class="max-w-56 text-xs text-gray-500">
                        @if ($row?->edit_reason)
                            {{ $row->edit_reason }} <span class="whitespace-nowrap">({{ $row->editedBy?->name }})</span>
                        @elseif ($row?->terminal)
                            {{ $row->terminal->displayName() }}
                        @endif
                    </td>
                    <td class="text-right">
                        @can('update', \App\Domain\HR\Models\Attendance::class)
                            <x-ui.button size="sm" variant="ghost" x-data
                                x-on:click="$dispatch('correct-attendance', {{ \Illuminate\Support\Js::from([
                                    'employee_id' => $employee->id,
                                    'name' => $employee->full_name,
                                    'date' => $date->toDateString(),
                                    'status' => $row?->status->value ?? 'present',
                                    'clock_in' => $row?->clock_in?->format('H:i') ?? ($row ? '' : $employee->workingShift()?->startOn($date)->format('H:i')),
                                    'clock_out' => $row?->clock_out?->format('H:i') ?? '',
                                ]) }})">Correct</x-ui.button>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif

    @include('hr.attendance.partials.correct-modal')
@endsection
