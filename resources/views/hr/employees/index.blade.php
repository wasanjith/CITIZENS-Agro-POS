@extends('layouts.app')

@section('title', 'Employees')

@section('content')
    <x-ui.page-header title="Employees" description="Staff details, salary, EPF and the login that clocks them in.">
        <x-ui.button :href="route('hr.employees.create')">Add employee</x-ui.button>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('hr.employees.index')" placeholder="Name, code, NIC or phone" class="mb-4">
        <x-ui.select name="filter[status]" :options="['active' => 'Working here', 'inactive' => 'Left']" :value="request('filter.status')" placeholder="Everyone" />
    </x-ui.filter-bar>

    @if ($employees->isEmpty())
        <x-ui.empty-state title="No employees yet" description="Add each member of staff and link them to their login so the POS sign-in clocks them in." />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="code" default="code">Code</x-ui.th-sortable>
                <x-ui.th-sortable column="full_name">Name</x-ui.th-sortable>
                <th>Designation</th>
                <th>Login</th>
                <th>Shift</th>
                <th>EPF</th>
                <x-ui.th-sortable column="basic_salary" class="text-right">Basic (Rs.)</x-ui.th-sortable>
                <th></th>
            </x-slot:head>
            @foreach ($employees as $employee)
                <tr @class(['text-gray-400' => ! $employee->is_active])>
                    <td class="font-mono text-xs">{{ $employee->code }}</td>
                    <td>
                        <a href="{{ route('hr.employees.show', $employee) }}" class="font-medium text-brand-700 hover:underline">{{ $employee->full_name }}</a>
                        @if ($employee->name_si)<span class="block font-sinhala text-xs text-gray-500">{{ $employee->name_si }}</span>@endif
                    </td>
                    <td>{{ $employee->designation }}</td>
                    <td class="text-gray-600">{{ $employee->user?->username ?? '—' }}</td>
                    <td class="text-gray-600">{{ $employee->shift?->name ?? 'Default' }}</td>
                    <td>{{ $employee->is_epf_member ? ($employee->epf_no ?: 'Yes') : 'No' }}</td>
                    <td class="text-right tabular">{{ number_format((float) $employee->basic_salary, 2) }}</td>
                    <td class="text-right">
                        @unless ($employee->is_active)<x-ui.badge>Left {{ $employee->leave_date?->format('Y-m-d') }}</x-ui.badge>@endunless
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$employees" />
    @endif
@endsection
