@extends('layouts.app')

@section('title', 'My attendance')

@php
    $minutes = fn (int $value) => $value > 0 ? intdiv($value, 60).'h '.str_pad((string) ($value % 60), 2, '0', STR_PAD_LEFT).'m' : '';
@endphp

@section('content')
    <x-ui.page-header title="My attendance" :description="$employee ? $employee->full_name.' · '.$start->format('F Y') : null">
        @if ($employee)
            <x-ui.button variant="secondary" :href="route('hr.attendance.mine', ['month' => $start->copy()->subMonth()->format('Y-m')])">&larr; {{ $start->copy()->subMonth()->format('M') }}</x-ui.button>
            @if ($start->lt(today()->startOfMonth()))
                <x-ui.button variant="secondary" :href="route('hr.attendance.mine', ['month' => $start->copy()->addMonth()->format('Y-m')])">{{ $start->copy()->addMonth()->format('M') }} &rarr;</x-ui.button>
            @endif
        @endif
    </x-ui.page-header>

    @if (! $employee)
        <x-ui.empty-state title="Your login is not linked to an employee" description="Ask the owner to link it on the Employees page. Then your first PIN sign-in each day clocks you in." />
    @else
        @foreach (['leave', 'from_date', 'to_date', 'leave_type_id'] as $field)
            @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
        @endforeach

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.card title="Today">
                @if ($today?->clock_in)
                    <p class="text-sm">In at <span class="font-semibold">{{ $today->clock_in->format('H:i') }}</span>@if ($today->clock_out), out at <span class="font-semibold">{{ $today->clock_out->format('H:i') }}</span>@endif</p>
                    @if ($today->late_minutes)<p class="text-xs text-amber-700">{{ $today->late_minutes }} minutes late</p>@endif
                    @unless ($today->clock_out)
                        <form method="POST" action="{{ route('hr.clock-out') }}" class="mt-3">
                            @csrf
                            <x-ui.button type="submit" variant="secondary">Clock out</x-ui.button>
                        </form>
                    @endunless
                @elseif ($today)
                    <p class="text-sm">{{ $today->status->label() }}</p>
                @else
                    <p class="text-sm text-gray-500">Not clocked in. Signing in with your PIN at a counter clocks you in.</p>
                    <form method="POST" action="{{ route('hr.clock-in') }}" class="mt-3">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">Clock in now</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
            <x-ui.stat-tile label="Worked this month" :value="$summary['worked'].' days'" :hint="'of '.$summary['working'].' working days so far'" />
            <x-ui.stat-tile label="Leave / no-pay" :value="$summary['paid_leave'].' / '.$summary['no_pay']" hint="Paid leave days / no-pay days" />
            <x-ui.stat-tile label="Overtime" :value="round($summary['ot_minutes'] / 60, 1).' h'" />
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.table>
                    <x-slot:head>
                        <th>Day</th>
                        <th>Status</th>
                        <th>In</th>
                        <th>Out</th>
                        <th class="text-right">Late</th>
                        <th class="text-right">Overtime</th>
                    </x-slot:head>
                    @foreach ($rows as $day)
                        <tr @class(['text-gray-400' => ! $day['working'] && ! $day['row']])>
                            <td class="whitespace-nowrap">{{ $day['date']->format('D j') }} @if ($day['holiday'])<span class="text-xs text-violet-700">{{ $day['holiday'] }}</span>@endif</td>
                            <td>
                                @if ($day['row'])
                                    <x-ui.badge :color="$day['row']->status->color()">{{ $day['row']->status->label() }}</x-ui.badge>
                                @elseif ($day['working'] && ! $day['date']->isToday())
                                    <x-ui.badge color="red">No record</x-ui.badge>
                                @elseif (! $day['working'])
                                    Day off
                                @endif
                            </td>
                            <td class="tabular">{{ $day['row']?->clock_in?->format('H:i') }}</td>
                            <td class="tabular">{{ $day['row']?->clock_out?->format('H:i') }} @if ($day['row']?->missingClockOut())<x-ui.badge color="amber">Not clocked out</x-ui.badge>@endif</td>
                            <td class="text-right tabular text-amber-700">{{ $minutes($day['row']->late_minutes ?? 0) }}</td>
                            <td class="text-right tabular">{{ $minutes($day['row']->ot_minutes ?? 0) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
                <p class="mt-2 text-xs text-gray-500">Something wrong? Tell the owner; only they can correct attendance.</p>
            </div>

            <div class="space-y-6">
                <x-ui.card title="My leave ({{ today()->year }})">
                    @foreach ($balances as $balance)
                        <div class="flex justify-between py-1 text-sm">
                            <span>{{ $balance['type']->name }}</span>
                            <span class="tabular">{{ $balance['remaining'] !== null ? $balance['remaining'].' left' : $balance['taken'].' taken' }}</span>
                        </div>
                    @endforeach
                    <div class="mt-3 space-y-2 text-sm">
                        @foreach ($requests as $request)
                            <div class="flex items-center justify-between gap-2">
                                <span><x-ui.badge :color="$request->status->color()">{{ $request->status->label() }}</x-ui.badge> {{ $request->periodLabel() }}</span>
                                @can('withdraw', $request)
                                    <form method="POST" action="{{ route('hr.leave.cancel', $request) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="ghost">Withdraw</x-ui.button>
                                    </form>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>

                <x-ui.card title="Ask for leave">
                    <form method="POST" action="{{ route('hr.leave.request') }}" class="grid gap-3" x-data="{ half: @js((bool) old('half_day')) }">
                        @csrf
                        <x-ui.select name="leave_type_id" label="Type" :options="$leaveTypes" required />
                        <x-ui.date-input name="from_date" label="From" required />
                        <div x-show="! half"><x-ui.date-input name="to_date" label="To (last day)" hint="Leave empty for one day." /></div>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="half_day" value="1" x-model="half" class="rounded border-gray-300 text-brand-600"> Half day</label>
                        <x-ui.input name="reason" label="Reason" maxlength="255" />
                        <x-ui.button type="submit">Send request</x-ui.button>
                    </form>
                </x-ui.card>
            </div>
        </div>
    @endif
@endsection
