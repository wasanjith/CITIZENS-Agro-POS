@extends('layouts.app')

@section('title', 'Payslip '.$payslip->employee_name)

@php
    $money = fn ($amount) => number_format((float) $amount, 2);
    $run = $payslip->payrollRun;
    $editable = $run->isEditable();
    $manual = $payslip->lines->where('is_manual', true)->values();
@endphp

@section('content')
    <x-ui.page-header :title="$payslip->employee_name" :description="'Payslip '.$run->label().' · '.$run->status->label()">
        <x-ui.button variant="secondary" :href="route('hr.payslips.pdf', ['payslip' => $payslip, 'lang' => 'si+en'])" target="_blank">PDF (Sinhala + English)</x-ui.button>
        <x-ui.button variant="secondary" :href="route('hr.payslips.pdf', ['payslip' => $payslip, 'lang' => 'si'])" target="_blank">PDF (Sinhala)</x-ui.button>
        <x-ui.button variant="secondary" :href="route('hr.payroll.show', $run)">Back to payroll</x-ui.button>
    </x-ui.page-header>

    @error('payslip')<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="grid gap-6 sm:grid-cols-2">
                <x-ui.card title="Earnings">
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt>Basic salary</dt><dd class="tabular">{{ $money($payslip->basic) }}</dd></div>
                        @if ((float) $payslip->no_pay_deduction)
                            <div class="flex justify-between text-red-700"><dt>No-pay ({{ $payslip->no_pay_days }} of {{ $payslip->working_days }} days)</dt><dd class="tabular">-{{ $money($payslip->no_pay_deduction) }}</dd></div>
                        @endif
                        @foreach ($payslip->lines->where('type', \App\Domain\HR\Enums\SalaryComponentType::Allowance) as $line)
                            <div class="flex justify-between"><dt>{{ $line->component_name }}@if ($line->is_epf_applicable) <span class="text-xs text-gray-500">(EPF)</span>@endif</dt><dd class="tabular">{{ $money($line->amount) }}</dd></div>
                        @endforeach
                        @if ((float) $payslip->ot_amount)
                            <div class="flex justify-between"><dt>Overtime {{ $payslip->ot_hours }} h × {{ $money($payslip->ot_rate) }}</dt><dd class="tabular">{{ $money($payslip->ot_amount) }}</dd></div>
                        @endif
                        <div class="flex justify-between border-t border-gray-200 pt-1 font-semibold"><dt>Gross pay</dt><dd class="tabular">{{ $money($payslip->gross) }}</dd></div>
                    </dl>
                </x-ui.card>

                <x-ui.card title="Deductions">
                    <dl class="space-y-1 text-sm">
                        @if ($payslip->is_epf_member)
                            <div class="flex justify-between"><dt>EPF 8 % of {{ $money($payslip->epf_base) }}</dt><dd class="tabular">{{ $money($payslip->epf_employee) }}</dd></div>
                        @endif
                        @if ((float) $payslip->advance_deduction)
                            <div class="flex justify-between"><dt>Salary advance</dt><dd class="tabular">{{ $money($payslip->advance_deduction) }}</dd></div>
                        @endif
                        @foreach ($payslip->lines->where('type', \App\Domain\HR\Enums\SalaryComponentType::Deduction) as $line)
                            <div class="flex justify-between"><dt>{{ $line->component_name }}</dt><dd class="tabular">{{ $money($line->amount) }}</dd></div>
                        @endforeach
                        <div class="flex justify-between border-t border-gray-200 pt-1 font-semibold"><dt>Total deductions</dt><dd class="tabular">{{ $money($payslip->total_deductions) }}</dd></div>
                    </dl>
                </x-ui.card>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.stat-tile label="Net pay" :value="'Rs. '.$money($payslip->net)" :hint="$payslip->isPaid() ? 'Paid '.$payslip->paid_at->format('Y-m-d').' · '.$payslip->paid_from?->label() : 'Not paid yet'" />
                <x-ui.stat-tile label="Days" :value="$payslip->days_worked.' / '.$payslip->working_days" :hint="'Paid leave '.$payslip->paid_leave_days.' · no-pay '.$payslip->no_pay_days" />
                <x-ui.stat-tile label="Employer EPF + ETF" :value="'Rs. '.$money((float) $payslip->epf_employer + (float) $payslip->etf)" :hint="$payslip->is_epf_member ? 'EPF 12 % '.$money($payslip->epf_employer).' · ETF 3 % '.$money($payslip->etf) : 'Not an EPF member'" />
            </div>

            @if ($payslip->recoveries->isNotEmpty())
                <x-ui.card title="Advances recovered">
                    @foreach ($payslip->recoveries as $recovery)
                        <div class="flex justify-between text-sm"><a href="{{ route('hr.advances.show', $recovery->advance) }}" class="font-mono text-brand-700 hover:underline">{{ $recovery->advance->number }}</a><span class="tabular">{{ $money($recovery->amount) }}</span></div>
                    @endforeach
                </x-ui.card>
            @endif

            @include('finance.partials.entries', ['entries' => $entries])
        </div>

        <div>
            @if ($editable)
                <x-ui.card title="Change this payslip" description="Leave a box empty to use the calculated value.">
                    <form method="POST" action="{{ route('hr.payslips.update', $payslip) }}" class="grid gap-4"
                          x-data="{ lines: @js(old('lines', $manual->map(fn ($line) => ['name' => $line->component_name, 'type' => $line->type->value, 'amount' => (string) $line->amount, 'epf' => $line->is_epf_applicable])->all())) }">
                        @csrf
                        @method('PUT')
                        <x-ui.input name="no_pay_days" label="No-pay days" :value="$payslip->no_pay_days_override" inputmode="decimal" :hint="$payslip->no_pay_days_override === null ? 'From the attendance: '.$payslip->no_pay_days.'. Halves allowed (0.5).' : 'Set by hand. Empty it to use the attendance.'" />
                        <x-ui.input name="ot_hours" label="Overtime hours" :value="$payslip->ot_hours_override" inputmode="decimal" :hint="$payslip->ot_hours_override === null ? 'From the attendance: '.$payslip->ot_hours.' h' : 'Set by hand. Empty it to use the attendance.'" />
                        <x-ui.money-input name="advance" label="Advance to recover this month" :value="$payslip->advance_override" :hint="$payslip->advance_override === null ? 'Planned installments: Rs. '.$money($payslip->advance_deduction) : 'Set by hand. Empty it for the planned installments.'" />

                        <div>
                            <p class="text-sm font-medium text-gray-700">Bonus or extra deduction</p>
                            <template x-for="(line, index) in lines" :key="index">
                                <div class="mt-2 grid grid-cols-6 items-center gap-2">
                                    <input type="text" :name="`lines[${index}][name]`" x-model="line.name" placeholder="Festival bonus" maxlength="80" class="col-span-6 rounded-md border-gray-300 text-sm" aria-label="Name">
                                    <select :name="`lines[${index}][type]`" x-model="line.type" class="col-span-3 rounded-md border-gray-300 text-sm" aria-label="Kind">
                                        @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                    </select>
                                    <input type="text" :name="`lines[${index}][amount]`" x-model="line.amount" inputmode="decimal" placeholder="Rs." class="col-span-2 rounded-md border-gray-300 text-sm" aria-label="Amount">
                                    <button type="button" class="text-sm text-red-700" x-on:click="lines.splice(index, 1)" aria-label="Remove">✕</button>
                                    <label class="col-span-6 flex items-center gap-1 text-xs text-gray-600" x-show="line.type === 'allowance'">
                                        <input type="hidden" :name="`lines[${index}][epf]`" value="0">
                                        <input type="checkbox" :name="`lines[${index}][epf]`" value="1" x-model="line.epf" class="rounded border-gray-300 text-brand-600"> EPF applies
                                    </label>
                                </div>
                            </template>
                            <x-ui.button size="sm" variant="ghost" class="mt-2" x-on:click="lines.push({ name: '', type: 'allowance', amount: '', epf: false })">+ Add a line</x-ui.button>
                        </div>

                        <x-ui.input name="note" label="Note (printed on the payslip)" :value="$payslip->note" maxlength="255" />
                        <x-ui.button type="submit">Recalculate payslip</x-ui.button>
                    </form>
                </x-ui.card>
            @else
                <x-ui.card title="Approved">
                    <p class="text-sm text-gray-600">This payroll is {{ strtolower($run->status->label()) }}. To change a payslip, reopen the payroll (only before anyone is paid).</p>
                    @if ($payslip->note)<p class="mt-2 text-sm">Note: {{ $payslip->note }}</p>@endif
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
