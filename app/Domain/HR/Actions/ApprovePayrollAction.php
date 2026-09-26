<?php

namespace App\Domain\HR\Actions;

use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Services\JournalService;
use App\Domain\HR\Enums\PayrollStatus;
use App\Domain\HR\Enums\SalaryAdvanceStatus;
use App\Domain\HR\Models\PayrollRun;
use App\Domain\HR\Models\Payslip;
use App\Domain\HR\Models\SalaryAdvance;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approve a month's payroll: the advance installments are taken off the advances and
 * the salaries are posted as owed to the staff.
 *
 * Journal: Dr Salaries (gross less other deductions), Dr EPF/ETF employer;
 *          Cr EPF payable (8 % + 12 %), Cr ETF payable, Cr Staff advances, Cr Salaries payable (net).
 */
class ApprovePayrollAction
{
    public const EVENT = 'payroll.approved';

    public function __construct(private readonly JournalService $journal) {}

    public function handle(PayrollRun $run, User $user): PayrollRun
    {
        return DB::transaction(function () use ($run, $user): PayrollRun {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! $run->isEditable()) {
                throw ValidationException::withMessages(['payroll' => "The payroll for {$run->label()} is already {$run->status->label()}."]);
            }

            $payslips = $run->payslips()->orderBy('id')->get();

            if ($payslips->isEmpty()) {
                throw ValidationException::withMessages(['payroll' => 'There are no payslips to approve.']);
            }

            $negative = $payslips->first(fn (Payslip $payslip) => Money::of($payslip->net)->isNegative());

            if ($negative !== null) {
                throw ValidationException::withMessages(['payroll' => "{$negative->employee_name}'s net pay is below zero. Lower the deductions on the payslip first."]);
            }

            foreach ($payslips as $payslip) {
                $this->recoverAdvances($payslip);
            }

            $sum = fn (string $column): BigDecimal => $payslips->reduce(fn (BigDecimal $total, Payslip $payslip) => $total->plus($payslip->{$column}), Money::zero());
            $epfEmployee = $sum('epf_employee');
            $epfEmployer = $sum('epf_employer');
            $etf = $sum('etf');

            $this->journal->post("Salaries {$run->label()}", $run->end(), [
                ['account' => SystemAccount::Salaries, 'debit' => $sum('gross')->minus($sum('other_deductions')), 'memo' => 'Gross pay less other deductions'],
                ['account' => SystemAccount::EpfEtfExpense, 'debit' => $epfEmployer->plus($etf), 'memo' => 'Employer EPF 12 % and ETF 3 %'],
                ['account' => SystemAccount::EpfPayable, 'credit' => $epfEmployee->plus($epfEmployer), 'memo' => 'EPF employee and employer'],
                ['account' => SystemAccount::EtfPayable, 'credit' => $etf],
                ['account' => SystemAccount::StaffAdvances, 'credit' => $sum('advance_deduction'), 'memo' => 'Advance installments recovered'],
                ['account' => SystemAccount::SalariesPayable, 'credit' => $sum('net'), 'memo' => 'Net pay owed to the staff'],
            ], $run, self::EVENT, $user->id);

            $run->forceFill([
                'status' => PayrollStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            return $run;
        });
    }

    /**
     * Spread the payslip's advance deduction over the employee's open advances, oldest first.
     */
    private function recoverAdvances(Payslip $payslip): void
    {
        $left = Money::of($payslip->advance_deduction);

        if (! $left->isPositive()) {
            return;
        }

        $advances = SalaryAdvance::query()
            ->where('employee_id', $payslip->employee_id)
            ->where('status', SalaryAdvanceStatus::Active)
            ->orderBy('date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($advances as $advance) {
            $amount = $advance->outstanding()->isLessThan($left) ? $advance->outstanding() : $left;

            if (! $amount->isPositive()) {
                continue;
            }

            $advance->recoveries()->create(['payslip_id' => $payslip->id, 'amount' => (string) $amount]);
            $recovered = Money::of($advance->recovered_amount)->plus($amount);
            $advance->forceFill([
                'recovered_amount' => (string) $recovered,
                'status' => $recovered->isGreaterThanOrEqualTo($advance->amount) ? SalaryAdvanceStatus::Recovered : SalaryAdvanceStatus::Active,
            ])->save();

            $left = $left->minus($amount);
        }

        if ($left->isPositive()) {
            throw ValidationException::withMessages(['payroll' => "{$payslip->employee_name}'s advance deduction is more than the advances still owed. Recalculate the payroll."]);
        }
    }
}
