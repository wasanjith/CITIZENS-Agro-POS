@extends('layouts.app')

@section('title', $employee->full_name)

@section('content')
    <x-ui.page-header :title="$employee->code.' '.$employee->full_name" :description="collect([$employee->designation, $employee->employment_type->label(), 'joined '.$employee->join_date->format('Y-m-d')])->filter()->implode(' Â· ')">
        <x-ui.button variant="secondary" :href="route('hr.attendance.month', ['month' => today()->format('Y-m')])">Attendance</x-ui.button>
        @can('create', \App\Domain\HR\Models\SalaryAdvance::class)
            <x-ui.button variant="secondary" :href="route('hr.advances.create', ['employee' => $employee->id])">Give advance</x-ui.button>
        @endcan
        <x-ui.button :href="route('hr.employees.edit', $employee)">Edit</x-ui.button>
    </x-ui.page-header>

    @unless ($employee->is_active)
        <x-ui.alert type="warning" class="mb-4">No longer working here{{ $employee->leave_date ? ' (left on '.$employee->leave_date->format('Y-m-d').')' : '' }}.</x-ui.alert>
    @endunless

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-tile label="Basic salary" :value="'Rs. '.number_format((float) $employee->basic_salary, 2)" :hint="$employee->is_epf_member ? 'EPF member'.($employee->epf_no ? ' Â· No. '.$employee->epf_no : '') : 'Not in EPF'" />
        <x-ui.stat-tile label="This month so far" :value="$month['worked'].' / '.$month['working'].' days'" :hint="'No-pay '.$month['no_pay'].' Â· paid leave '.$month['paid_leave'].' Â· OT '.round($month['ot_minutes'] / 60, 1).' h'" />
        <x-ui.stat-tile label="Advances still owed" :value="'Rs. '.number_format($owedOnAdvances, 2)" />
        <x-ui.stat-tile label="Login" :value="$employee->user?->username ?? 'â€”'" :hint="$employee->user ? 'First PIN sign-in of the day clocks in' : 'Link a login so they can clock in'" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Details">
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <dt class="text-gray-500">Name in Sinhala</dt><dd class="font-sinhala">{{ $employee->name_si ?: 'â€”' }}</dd>
                <dt class="text-gray-500">NIC</dt><dd>{{ $employee->nic ?: 'â€”' }}</dd>
                <dt class="text-gray-500">Date of birth</dt><dd>{{ $employee->dob?->format('Y-m-d') ?? 'â€”' }}</dd>
                <dt class="text-gray-500">Phone</dt><dd>{{ $employee->phone ?: 'â€”' }}</dd>
                <dt class="text-gray-500">Address</dt><dd>{{ $employee->address ?: 'â€”' }}</dd>
                <dt class="text-gray-500">Shift</dt><dd>{{ $employee->workingShift()?->name ?? 'â€”' }} {{ $employee->workingShift() ? '('.$employee->workingShift()->hoursLabel().', '.$employee->workingShift()->daysLabel().')' : '' }}</dd>
                <dt class="text-gray-500">Bank</dt><dd>{{ trim(($employee->bank_name ?? '').' '.($employee->bank_account_no ?? '')) ?: 'â€”' }}</dd>
            </dl>
        </x-ui.card>

        <x-ui.card title="Allowances and deductions">
            @forelse ($employee->salaryComponents as $salaryComponent)
                <div class="flex justify-between py-1 text-sm">
                    <span>{{ $salaryComponent->name }} <span class="text-xs text-gray-500">{{ $salaryComponent->type->label() }}</span></span>
                    <span class="tabular">{{ $salaryComponent->getRelation('pivot')->getAttribute('value_override') !== null ? ($salaryComponent->calc === \App\Domain\HR\Enums\SalaryComponentCalc::PercentBasic ? $salaryComponent->getRelation('pivot')->getAttribute('value_override').'%' : 'Rs. '.number_format((float) $salaryComponent->getRelation('pivot')->getAttribute('value_override'), 2)) : $salaryComponent->valueLabel() }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">None.</p>
            @endforelse
        </x-ui.card>

        <x-ui.card title="Leave this year ({{ today()->year }})">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-gray-500"><tr><th class="py-1">Type</th><th class="text-right">Entitled</th><th class="text-right">Taken</th><th class="text-right">Waiting</th><th class="text-right">Left</th></tr></thead>
                <tbody>
                    @foreach ($balances as $balance)
                        <tr>
                            <td class="py-1">{{ $balance['type']->name }}</td>
                            <td class="text-right tabular">{{ $balance['entitled'] ?? 'â€”' }}</td>
                            <td class="text-right tabular">{{ $balance['taken'] }}</td>
                            <td class="text-right tabular">{{ $balance['pending'] }}</td>
                            <td class="text-right tabular font-medium">{{ $balance['remaining'] ?? 'â€”' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-3 space-y-1 text-sm">
                @foreach ($leaveRequests as $request)
                    <p><x-ui.badge :color="$request->status->color()">{{ $request->status->label() }}</x-ui.badge> {{ $request->leaveType->name }} Â· {{ $request->periodLabel() }} ({{ $request->days }} d)</p>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card title="Payslips">
            @forelse ($payslips as $payslip)
                <div class="flex justify-between py-1 text-sm">
                    <a href="{{ route('hr.payslips.show', $payslip) }}" class="text-brand-700 hover:underline">{{ $payslip->payrollRun->label() }}</a>
                    <span class="tabular">Rs. {{ number_format((float) $payslip->net, 2) }} @if ($payslip->isPaid())<x-ui.badge color="green">Paid</x-ui.badge>@endif</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">No payslips yet.</p>
            @endforelse
        </x-ui.card>

        <x-ui.card title="Salary advances" class="lg:col-span-2">
            @forelse ($advances as $advance)
                <div class="flex justify-between py-1 text-sm">
                    <span><a href="{{ route('hr.advances.show', $advance) }}" class="font-mono text-brand-700 hover:underline">{{ $advance->number }}</a> Â· {{ $advance->date->format('Y-m-d') }} Â· <x-ui.badge :color="$advance->status->color()">{{ $advance->status->label() }}</x-ui.badge></span>
                    <span class="tabular">Rs. {{ number_format((float) $advance->amount, 2) }} Â· recovered {{ number_format((float) $advance->recovered_amount, 2) }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">No advances.</p>
            @endforelse
        </x-ui.card>
    </div>
@endsection
