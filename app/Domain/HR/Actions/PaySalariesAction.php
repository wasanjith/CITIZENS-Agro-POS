<?php

namespace App\Domain\HR\Actions;

use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Services\JournalService;
use App\Domain\HR\Enums\PayrollStatus;
use App\Domain\HR\Models\PayrollRun;
use App\Domain\HR\Models\Payslip;
use App\Domain\HR\Services\HrCash;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pay the net salary of the chosen payslips of an approved payroll, from the drawer,
 * the cash at home or a bank account. One entry per payslip:
 * Dr Salaries payable, Cr Drawer / Cash at home / Bank. The run is Paid once every
 * payslip is.
 */
class PaySalariesAction
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly HrCash $cash,
    ) {}

    /**
     * @param  list<int>  $payslipIds
     * @param  array{paid_from: string, bank_account_id?: int|string|null, date: string, reference?: string|null}  $data
     * @return int number of payslips paid
     */
    public function handle(PayrollRun $run, array $payslipIds, array $data, User $user): int
    {
        [$from, $bank] = $this->cash->resolveSource($data['paid_from'], $data['bank_account_id'] ?? null);
        $date = Carbon::parse($data['date'])->startOfDay();
        $reference = filled($data['reference'] ?? null) ? mb_substr((string) $data['reference'], 0, 100) : null;

        if ($payslipIds === []) {
            throw ValidationException::withMessages(['payslips' => 'Tick the salaries to pay.']);
        }

        return DB::transaction(function () use ($run, $payslipIds, $from, $bank, $date, $reference, $user): int {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($run->status !== PayrollStatus::Approved) {
                throw ValidationException::withMessages(['payslips' => $run->status === PayrollStatus::Paid ? 'Every salary of this month is already paid.' : 'Approve the payroll before paying salaries.']);
            }

            $payslips = $run->payslips()->whereIn('id', $payslipIds)->whereNull('paid_at')->orderBy('employee_name')->lockForUpdate()->get();

            if ($payslips->isEmpty()) {
                throw ValidationException::withMessages(['payslips' => 'Those salaries are already paid.']);
            }

            foreach ($payslips as $payslip) {
                /** @var Payslip $payslip */
                $amount = Money::of($payslip->net);
                $description = "Salary {$run->month} paid to {$payslip->employee_name}";
                $paid = $amount->isPositive()
                    ? $this->cash->payOut($from, $bank, $amount, $date, $description, $reference, $payslip, $user->id)
                    : ['account' => SystemAccount::Safe, 'drawer_session_id' => null];

                $this->journal->post($description, $date, [
                    ['account' => SystemAccount::SalariesPayable, 'debit' => $amount],
                    ['account' => $paid['account'], 'credit' => $amount],
                ], $payslip, 'salary.paid', $user->id);

                $payslip->forceFill([
                    'paid_at' => $date->isToday() ? now() : $date,
                    'paid_from' => $from,
                    'bank_account_id' => $bank?->id,
                    'drawer_session_id' => $paid['drawer_session_id'],
                    'payment_reference' => $reference,
                    'paid_by' => $user->id,
                ])->save();
            }

            if (! $run->payslips()->whereNull('paid_at')->exists()) {
                $run->forceFill(['status' => PayrollStatus::Paid, 'paid_at' => now()])->save();
            }

            return $payslips->count();
        });
    }
}
