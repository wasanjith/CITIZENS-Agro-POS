@extends('layouts.app')

@section('title', 'Payroll '.$run->label())

@php
    use App\Domain\HR\Enums\PayrollStatus;
    $money = fn ($amount) => number_format((float) $amount, 2);
    $unpaid = $payslips->whereNull('paid_at');
    $epfTotal = (float) $run->total_epf_employee + (float) $run->total_epf_employer + (float) $run->total_etf;
@endphp

@section('content')
    <x-ui.page-header :title="'Payroll '.$run->label()" :description="$run->status->label().' Â· calculated by '.$run->createdBy->name.($run->approvedBy ? ' Â· approved by '.$run->approvedBy->name.' '.$run->approved_at->format('Y-m-d') : '')">
        <x-ui.button variant="secondary" :href="route('hr.payroll.pdf', $run)" target="_blank">Payslips PDF</x-ui.button>
        @if ($run->status === PayrollStatus::Calculated)
            <form method="POST" action="{{ route('hr.payroll.recalculate', $run) }}">@csrf<x-ui.button type="submit" variant="secondary">Recalculate</x-ui.button></form>
            <x-ui.button variant="ghost" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'delete-run')">Delete</x-ui.button>
            <x-ui.button x-data x-on:click="$dispatch('open-modal', 'approve-run')">Approve</x-ui.button>
        @elseif ($run->status === PayrollStatus::Approved && $payslips->whereNotNull('paid_at')->isEmpty() && ! $run->epf_etf_paid_at)
            <x-ui.button variant="secondary" x-data x-on:click="$dispatch('open-modal', 'reopen-run')">Reopen</x-ui.button>
        @endif
    </x-ui.page-header>

    @foreach (['payroll', 'payslips', 'paid_from', 'bank_account_id', 'reason', 'date'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-tile label="Gross pay" :value="'Rs. '.$money($run->total_gross)" :hint="$payslips->count().' '.str('employee')->plural($payslips->count())" />
        <x-ui.stat-tile label="Deductions" :value="'Rs. '.$money($run->total_deductions)" hint="EPF 8 %, advances, other" />
        <x-ui.stat-tile label="Net pay" :value="'Rs. '.$money($run->total_net)" :hint="$unpaid->isEmpty() ? 'All paid' : $unpaid->count().' not paid yet'" />
        <x-ui.stat-tile label="EPF + ETF to the funds" :value="'Rs. '.number_format($epfTotal, 2)" :hint="'EPF '.$money((float) $run->total_epf_employee + (float) $run->total_epf_employer).' Â· ETF '.$money($run->total_etf).($run->epf_etf_paid_at ? ' Â· paid '.$run->epf_etf_paid_at->format('Y-m-d') : '')" />
    </div>

    @if ($run->status === PayrollStatus::Calculated)
        <x-ui.alert type="info" class="mb-4">Open a payslip to change no-pay days, overtime hours, the advance installment or to add a bonus or deduction. Approving posts the salaries to the accounts and takes the advance installments.</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('hr.payroll.pay', $run) }}" id="pay-form" x-data="{ selected: [], from: @js(old('paid_from', 'safe')) }">
        @csrf
        <x-ui.table>
            <x-slot:head>
                @if ($run->status === PayrollStatus::Approved)
                    <th class="w-8"><span class="sr-only">Pay</span></th>
                @endif
                <th>Employee</th>
                <th class="text-right">Days</th>
                <th class="text-right">Basic</th>
                <th class="text-right">No-pay</th>
                <th class="text-right">Allowances</th>
                <th class="text-right">OT</th>
                <th class="text-right">Gross</th>
                <th class="text-right">EPF 8 %</th>
                <th class="text-right">Advance</th>
                <th class="text-right">Other</th>
                <th class="text-right">Net</th>
                <th>Paid</th>
            </x-slot:head>
            @foreach ($payslips as $payslip)
                <tr>
                    @if ($run->status === PayrollStatus::Approved)
                        <td>
                            @unless ($payslip->isPaid())
                                <input type="checkbox" name="payslips[]" value="{{ $payslip->id }}" x-model="selected" class="rounded border-gray-300 text-brand-600" aria-label="Pay {{ $payslip->employee_name }}">
                            @endunless
                        </td>
                    @endif
                    <td>
                        <a href="{{ route('hr.payslips.show', $payslip) }}" class="font-medium text-brand-700 hover:underline">{{ $payslip->employee_name }}</a>
                        @if ($payslip->no_pay_days_override !== null || $payslip->ot_hours_override !== null || $payslip->advance_override !== null || $payslip->note)
                            <span class="text-xs text-amber-700" title="Changed by hand">âœŽ</span>
                        @endif
                        <span class="block text-xs text-gray-500">{{ $payslip->designation }}</span>
                    </td>
                    <td class="text-right tabular" title="Worked / working days">{{ $payslip->days_worked }}/{{ $payslip->working_days }}</td>
                    <td class="text-right tabular">{{ $money($payslip->basic) }}</td>
                    <td class="text-right tabular text-red-700">{{ (float) $payslip->no_pay_deduction ? '-'.$money($payslip->no_pay_deduction) : '' }}<span class="block text-xs text-gray-500">{{ (float) $payslip->no_pay_days ? $payslip->no_pay_days.' d' : '' }}</span></td>
                    <td class="text-right tabular">{{ (float) $payslip->allowances ? $money($payslip->allowances) : '' }}</td>
                    <td class="text-right tabular">{{ (float) $payslip->ot_amount ? $money($payslip->ot_amount) : '' }}<span class="block text-xs text-gray-500">{{ (float) $payslip->ot_hours ? $payslip->ot_hours.' h' : '' }}</span></td>
                    <td class="text-right tabular">{{ $money($payslip->gross) }}</td>
                    <td class="text-right tabular">{{ $payslip->is_epf_member ? $money($payslip->epf_employee) : 'â€”' }}</td>
                    <td class="text-right tabular">{{ (float) $payslip->advance_deduction ? $money($payslip->advance_deduction) : '' }}</td>
                    <td class="text-right tabular">{{ (float) $payslip->other_deductions ? $money($payslip->other_deductions) : '' }}</td>
                    <td class="text-right tabular font-semibold">{{ $money($payslip->net) }}</td>
                    <td class="text-xs">
                        @if ($payslip->isPaid())
                            <x-ui.badge color="green">{{ $payslip->paid_at->format('Y-m-d') }}</x-ui.badge>
                            <span class="block text-gray-500">{{ $payslip->paid_from?->label() }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        @if ($run->status === PayrollStatus::Approved && $unpaid->isNotEmpty())
            <x-ui.card title="Pay salaries" description="Tick the people to pay now. Each one is recorded separately, so you can pay some in cash and others by bank transfer." class="mt-6">
                <div class="grid gap-4 sm:grid-cols-4">
                    <x-ui.select name="paid_from" label="Paid from" :options="$sources" required x-model="from" />
                    <div x-show="from === 'bank'" x-cloak><x-ui.select name="bank_account_id" label="Bank account" :options="$banks" placeholder="Chooseâ€¦" /></div>
                    <x-ui.date-input name="date" label="Date" :value="today()" required />
                    <x-ui.input name="reference" label="Reference" maxlength="100" placeholder="Transfer / cheque no." />
                </div>
                <p x-show="from === 'cash_drawer' && ! @js($drawerOpen)" x-cloak class="mt-2 text-sm text-amber-700">No drawer is open at the main cashier.</p>
                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="selected = {{ \Illuminate\Support\Js::from($unpaid->pluck('id')->map(fn ($id) => (string) $id)->values()) }}">Tick all</x-ui.button>
                    <x-ui.button type="submit" x-bind:disabled="selected.length === 0"><span x-text="'Pay ' + selected.length + ' selected'"></span></x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        @endif
    </form>

    @if (in_array($run->status, [PayrollStatus::Approved, PayrollStatus::Paid], true) && $epfTotal > 0)
        <x-ui.card title="EPF and ETF" class="mt-6" description="Send EPF (employee 8 % + employer 12 %) and ETF (3 %) to the funds by the end of next month.">
            @if ($run->epf_etf_paid_at)
                <p class="text-sm">Paid on {{ $run->epf_etf_paid_at->format('Y-m-d') }} by {{ $run->epfEtfPaidBy?->name }}{{ $run->epf_etf_reference ? ' Â· ref. '.$run->epf_etf_reference : '' }}.</p>
            @else
                <form method="POST" action="{{ route('hr.payroll.epf-etf', $run) }}" class="grid items-end gap-4 sm:grid-cols-5" x-data="{ from: 'bank' }">
                    @csrf
                    <x-ui.select name="paid_from" label="Paid from" :options="$epfSources" required x-model="from" />
                    <div x-show="from === 'bank'"><x-ui.select name="bank_account_id" label="Bank account" :options="$banks" placeholder="Chooseâ€¦" /></div>
                    <x-ui.date-input name="date" label="Date" :value="today()" required />
                    <x-ui.input name="reference" label="Reference" maxlength="100" placeholder="Receipt no." />
                    <x-ui.button type="submit">Record Rs. {{ number_format($epfTotal, 2) }} paid</x-ui.button>
                </form>
                <p class="mt-2 text-xs text-gray-500"><a href="{{ route('hr.reports.epf-etf', ['month' => $run->month]) }}" class="text-brand-700 hover:underline">EPF / ETF list for the forms</a></p>
            @endif
        </x-ui.card>
    @endif

    @include('finance.partials.entries', ['entries' => $entries])

    <x-ui.confirm-modal name="approve-run" :action="route('hr.payroll.approve', $run)" title="Approve payroll {{ $run->label() }}?" confirm="Approve" variant="primary">
        Net pay of Rs. {{ $money($run->total_net) }} is posted as owed to the staff, EPF and ETF as owed to the funds, and advance installments are taken off the advances. You can reopen it until someone is paid.
    </x-ui.confirm-modal>

    <x-ui.confirm-modal name="delete-run" :action="route('hr.payroll.destroy', $run)" method="DELETE" title="Delete payroll {{ $run->label() }}?" confirm="Delete">
        The payslips and your changes on them are deleted. Nothing has been posted yet.
    </x-ui.confirm-modal>

    <x-ui.modal name="reopen-run" title="Reopen payroll {{ $run->label() }}?" max-width="md">
        <form method="POST" action="{{ route('hr.payroll.reopen', $run) }}" id="reopen-run-form" class="grid gap-4">
            @csrf
            <p class="text-sm text-gray-600">The approval is reversed in the accounts and the advance installments are given back, so you can correct and approve again.</p>
            <x-ui.input name="reason" label="Reason" required maxlength="200" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'reopen-run')">Back</x-ui.button>
            <x-ui.button type="submit" form="reopen-run-form">Reopen</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endsection
