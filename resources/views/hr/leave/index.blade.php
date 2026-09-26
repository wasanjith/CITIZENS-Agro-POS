@extends('layouts.app')

@section('title', 'Leave')

@section('content')
    <x-ui.page-header title="Leave" :description="$waiting ? $waiting.' '.str('request')->plural($waiting).' waiting for approval.' : 'Staff ask for leave on their My attendance page.'">
        @can('create', \App\Domain\HR\Models\LeaveRequest::class)
            <x-ui.button x-data x-on:click="$dispatch('open-modal', 'add-leave')">Enter leave</x-ui.button>
        @endcan
    </x-ui.page-header>

    @foreach (['leave', 'employee_id', 'leave_type_id', 'from_date', 'to_date'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    <x-ui.filter-bar :action="route('hr.leave.index')" :search="false" class="mb-4">
        <x-ui.select name="filter[status]" :options="$statuses" :value="request('filter.status')" placeholder="Any status" />
        <x-ui.select name="filter[employee]" :options="$employees" :value="request('filter.employee')" placeholder="Everyone" />
    </x-ui.filter-bar>

    @if ($requests->isEmpty())
        <x-ui.empty-state title="No leave found" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>Employee</th>
                <th>Type</th>
                <x-ui.th-sortable column="from_date" default="from_date">Days</x-ui.th-sortable>
                <th class="text-right">Working days</th>
                <th>Reason</th>
                <th>Status</th>
                <th></th>
            </x-slot:head>
            @foreach ($requests as $request)
                <tr>
                    <td class="font-medium">{{ $request->employee->full_name }}</td>
                    <td>{{ $request->leaveType->name }} @unless ($request->leaveType->is_paid)<span class="text-xs text-red-700">(no pay)</span>@endunless</td>
                    <td class="whitespace-nowrap">{{ $request->periodLabel() }}</td>
                    <td class="text-right tabular">{{ $request->days }}</td>
                    <td class="max-w-64 text-gray-600">{{ $request->reason }} <span class="block text-xs text-gray-400">asked by {{ $request->requestedBy?->name }} {{ $request->created_at->format('Y-m-d') }}</span></td>
                    <td>
                        <x-ui.badge :color="$request->status->color()">{{ $request->status->label() }}</x-ui.badge>
                        @if ($request->decidedBy)<span class="block text-xs text-gray-400">{{ $request->decidedBy->name }}{{ $request->decision_note ? ': '.$request->decision_note : '' }}</span>@endif
                    </td>
                    <td class="whitespace-nowrap text-right">
                        @if ($request->status === \App\Domain\HR\Enums\LeaveStatus::Pending)
                            @can('decide', \App\Domain\HR\Models\LeaveRequest::class)
                                <form method="POST" action="{{ route('hr.leave.approve', $request) }}" class="inline">@csrf<x-ui.button type="submit" size="sm">Approve</x-ui.button></form>
                                <form method="POST" action="{{ route('hr.leave.reject', $request) }}" class="inline">@csrf<x-ui.button type="submit" size="sm" variant="secondary">Reject</x-ui.button></form>
                            @endcan
                        @elseif ($request->status === \App\Domain\HR\Enums\LeaveStatus::Approved)
                            @can('decide', \App\Domain\HR\Models\LeaveRequest::class)
                                <form method="POST" action="{{ route('hr.leave.cancel', $request) }}" class="inline" x-data @submit="if (! confirm('Cancel this leave? Those days will count as absent unless they are corrected.')) $event.preventDefault()">@csrf<x-ui.button type="submit" size="sm" variant="ghost">Cancel</x-ui.button></form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$requests" />
    @endif

    @can('create', \App\Domain\HR\Models\LeaveRequest::class)
        <x-ui.modal name="add-leave" title="Enter leave" max-width="md" :show="$errors->hasAny(['employee_id', 'leave_type_id', 'from_date', 'to_date'])">
            <form method="POST" action="{{ route('hr.leave.store') }}" id="add-leave-form" class="grid gap-4" x-data="{ half: @js((bool) old('half_day')) }">
                @csrf
                <x-ui.select name="employee_id" label="Employee" :options="$employees" placeholder="Choose…" required />
                <x-ui.select name="leave_type_id" label="Type" :options="$types" required />
                <x-ui.date-input name="from_date" label="From" required />
                <div x-show="! half"><x-ui.date-input name="to_date" label="To (last day)" hint="Leave empty for one day." /></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="half_day" value="1" x-model="half" class="rounded border-gray-300 text-brand-600"> Half day</label>
                <x-ui.input name="reason" label="Reason" maxlength="255" />
                <x-ui.checkbox name="approve" label="Approve now" :checked="true" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'add-leave')">Cancel</x-ui.button>
                <x-ui.button type="submit" form="add-leave-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan
@endsection
