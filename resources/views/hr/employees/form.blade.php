@extends('layouts.app')

@section('title', $employee->exists ? 'Edit '.$employee->full_name : 'Add employee')

@section('content')
    <x-ui.page-header :title="$employee->exists ? $employee->code.' '.$employee->full_name : 'Add employee'" description="Salary changes apply to payrolls calculated after saving." />

    <form method="POST" action="{{ $employee->exists ? route('hr.employees.update', $employee) : route('hr.employees.store') }}" class="grid max-w-4xl gap-6" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        @if ($employee->exists)
            @method('PUT')
        @endif

        <x-ui.card title="Personal details">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="full_name" label="Full name" :value="$employee->full_name" required maxlength="150" />
                <x-ui.input name="name_si" label="Name in Sinhala" :value="$employee->name_si" maxlength="150" class="font-sinhala" hint="Printed on the Sinhala payslip." />
                <x-ui.input name="nic" label="NIC" :value="$employee->nic" maxlength="20" />
                <x-ui.date-input name="dob" label="Date of birth" :value="$employee->dob" />
                <x-ui.input name="phone" label="Phone" :value="$employee->phone" maxlength="20" inputmode="tel" />
                <x-ui.input name="address" label="Address" :value="$employee->address" maxlength="255" />
            </div>
        </x-ui.card>

        <x-ui.card title="Work">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="designation" label="Designation" :value="$employee->designation" maxlength="80" placeholder="Sales assistant, store keeper â€¦" />
                <x-ui.select name="employment_type" label="Employment" :options="$types" :value="$employee->employment_type" required />
                <x-ui.date-input name="join_date" label="Joined on" :value="$employee->join_date" required />
                <x-ui.date-input name="leave_date" label="Left on" :value="$employee->leave_date" hint="Only when they have left. They are paid up to this day." />
                <x-ui.select name="shift_id" label="Shift" :options="$shifts" :value="$employee->shift_id" placeholder="Shop's default shift" />
                <x-ui.select name="user_id" label="Login (clocks in with their PIN)" :options="$users" :value="$employee->user_id" placeholder="No login" />
                <x-ui.checkbox name="is_active" label="Working here" :checked="$employee->is_active" hint="Untick when they leave: they drop off the attendance sheet and later payrolls." class="sm:col-span-2" />
            </div>
        </x-ui.card>

        <x-ui.card title="Salary, EPF and bank">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.money-input name="basic_salary" label="Basic salary per month" :value="$employee->basic_salary" required />
                <div></div>
                <x-ui.checkbox name="is_epf_member" label="EPF / ETF member" :checked="$employee->is_epf_member" hint="8 % is taken from their pay; the shop adds 12 % EPF and 3 % ETF (rates in Settings â†’ HR & payroll)." />
                <x-ui.input name="epf_no" label="EPF number" :value="$employee->epf_no" maxlength="30" />
                <x-ui.input name="bank_name" label="Bank (for salary transfers)" :value="$employee->bank_name" maxlength="100" />
                <x-ui.input name="bank_account_no" label="Bank account number" :value="$employee->bank_account_no" maxlength="40" />
            </div>
        </x-ui.card>

        <x-ui.card title="Allowances and deductions" description="Tick what this employee gets every month. Leave the amount blank to use the usual amount.">
            @if ($components->isEmpty())
                <p class="text-sm text-gray-500">None set up yet. Add them under <a href="{{ route('hr.components.index') }}" class="text-brand-700 hover:underline">Allowances &amp; deductions</a>.</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach ($components as $salaryComponent)
                        @php $isAssigned = array_key_exists($salaryComponent->id, $assigned); @endphp
                        <div class="flex flex-wrap items-center gap-4 py-2" x-data="{ on: @js((bool) old("components.{$salaryComponent->id}.assigned", $isAssigned)) }">
                            <label class="flex min-w-60 flex-1 items-center gap-2 text-sm">
                                <input type="hidden" name="components[{{ $salaryComponent->id }}][assigned]" value="0">
                                <input type="checkbox" name="components[{{ $salaryComponent->id }}][assigned]" value="1" x-model="on" class="rounded border-gray-300 text-brand-600">
                                <span class="font-medium">{{ $salaryComponent->name }}</span>
                                <x-ui.badge :color="$salaryComponent->type === \App\Domain\HR\Enums\SalaryComponentType::Allowance ? 'green' : 'amber'">{{ $salaryComponent->type->label() }}</x-ui.badge>
                                <span class="text-gray-500">{{ $salaryComponent->valueLabel() }}</span>
                            </label>
                            <div x-show="on" class="w-44">
                                <input type="text" inputmode="decimal" name="components[{{ $salaryComponent->id }}][value]" value="{{ old("components.{$salaryComponent->id}.value", $assigned[$salaryComponent->id] ?? '') }}"
                                       placeholder="{{ $salaryComponent->calc === \App\Domain\HR\Enums\SalaryComponentCalc::PercentBasic ? 'Own %' : 'Own amount' }}" aria-label="Own amount for {{ $salaryComponent->name }}"
                                       class="block w-full rounded-md border-gray-300 text-sm">
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="$employee->exists ? route('hr.employees.show', $employee) : route('hr.employees.index')">Cancel</x-ui.button>
                <x-ui.button type="submit" x-bind:disabled="busy">Save employee</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
