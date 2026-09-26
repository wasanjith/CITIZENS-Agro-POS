@extends('layouts.app')

@section('title', 'Attendance '.$start->format('F Y'))

@section('content')
    <x-ui.page-header title="Attendance by month" :description="$start->format('F Y').' · P present · ½ half day · L leave · A absent · H holiday · – day off'">
        <x-ui.button variant="secondary" :href="route('hr.attendance.month', ['month' => $start->copy()->subMonth()->format('Y-m')])">&larr; {{ $start->copy()->subMonth()->format('M') }}</x-ui.button>
        @if ($start->lt(today()->startOfMonth()))
            <x-ui.button variant="secondary" :href="route('hr.attendance.month', ['month' => $start->copy()->addMonth()->format('Y-m')])">{{ $start->copy()->addMonth()->format('M') }} &rarr;</x-ui.button>
        @endif
        <x-ui.button :href="route('hr.attendance.index')">Today's sheet</x-ui.button>
    </x-ui.page-header>

    @foreach (['status', 'clock_in', 'clock_out', 'edit_reason', 'date'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    @if ($employees->isEmpty())
        <x-ui.empty-state title="Nobody was employed this month" />
    @else
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <table class="min-w-full text-center text-xs">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="sticky left-0 bg-gray-50 px-3 py-2 text-left">Employee</th>
                        @for ($day = $start->copy(); $day->lte($end); $day->addDay())
                            <th @class(['px-1 py-2 font-medium', 'text-purple-700' => isset($holidays[$day->toDateString()])]) title="{{ $holidays[$day->toDateString()] ?? $day->format('l') }}">
                                {{ $day->format('j') }}<span class="block text-[10px] font-normal text-gray-400">{{ substr($day->format('D'), 0, 2) }}</span>
                            </th>
                        @endfor
                        <th class="px-2 py-2">Worked</th>
                        <th class="px-2 py-2">Leave</th>
                        <th class="px-2 py-2">No-pay</th>
                        <th class="px-2 py-2">OT h</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($employees as $employee)
                        @php $shift = $employee->workingShift(); $days = $rows->get($employee->id, collect()); $summary = $summaries[$employee->id]; @endphp
                        <tr>
                            <td class="sticky left-0 whitespace-nowrap bg-white px-3 py-2 text-left text-sm font-medium">{{ $employee->full_name }}</td>
                            @for ($day = $start->copy(); $day->lte($end); $day->addDay())
                                @php
                                    $key = $day->toDateString();
                                    $row = $days->get($key);
                                    $off = ($shift && ! $shift->worksOn($day)) || isset($holidays[$key]);
                                    $outside = ! $employee->isEmployedOn($day);
                                    $letter = $row?->status->letter() ?? ($outside || $day->gt(today()) ? '' : ($off ? '–' : '?'));
                                    $colors = [
                                        'P' => 'bg-brand-50 text-brand-800', '½' => 'bg-amber-50 text-amber-800', 'L' => 'bg-sky-50 text-sky-800',
                                        'A' => 'bg-red-50 text-red-700', 'H' => 'bg-violet-50 text-violet-700', '?' => 'bg-red-50 text-red-400', '–' => 'text-gray-300',
                                    ];
                                    $title = $row ? $row->status->label().($row->clock_in ? ' '.$row->clock_in->format('H:i').'–'.($row->clock_out?->format('H:i') ?? '?') : '').($row->late_minutes ? " · late {$row->late_minutes} min" : '').($row->ot_minutes ? " · OT {$row->ot_minutes} min" : '') : ($letter === '?' ? 'No record: counts as absent' : '');
                                @endphp
                                <td class="p-0.5">
                                    @if ($letter !== '' && ! $day->gt(today()))
                                        <button type="button" title="{{ $key }} {{ $title }}"
                                            @class(['relative size-7 rounded font-semibold', $colors[$letter] ?? ''])
                                            @can('update', \App\Domain\HR\Models\Attendance::class)
                                                x-data x-on:click="$dispatch('correct-attendance', {{ \Illuminate\Support\Js::from([
                                                    'employee_id' => $employee->id,
                                                    'name' => $employee->full_name,
                                                    'date' => $key,
                                                    'status' => $row?->status->value ?? 'present',
                                                    'clock_in' => $row?->clock_in?->format('H:i') ?? ($row ? '' : $shift?->startOn($day)->format('H:i')),
                                                    'clock_out' => $row?->clock_out?->format('H:i') ?? ($row ? '' : $shift?->endOn($day)->format('H:i')),
                                                ]) }})"
                                            @else
                                                disabled
                                            @endcan
                                        >{{ $letter }}@if ($row?->late_minutes)<span class="absolute right-0.5 top-0.5 size-1.5 rounded-full bg-amber-500"></span>@endif</button>
                                    @endif
                                </td>
                            @endfor
                            <td class="px-2 tabular">{{ $summary['worked'] }}</td>
                            <td class="px-2 tabular">{{ $summary['paid_leave'] }}</td>
                            <td @class(['px-2 tabular', 'font-semibold text-red-700' => (float) (string) $summary['no_pay'] > 0])>{{ $summary['no_pay'] }}</td>
                            <td class="px-2 tabular">{{ round($summary['ot_minutes'] / 60, 1) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-500">A dot means they came late. "?" = no record on a working day: payroll counts it as absent (no-pay) unless it is corrected. Totals are up to today.</p>
    @endif

    @include('hr.attendance.partials.correct-modal')
@endsection
