<?php

namespace App\Domain\HR\Actions;

use App\Domain\Finance\Services\JournalService;
use App\Domain\HR\Enums\PayrollStatus;
use App\Domain\HR\Enums\SalaryAdvanceStatus;
use App\Domain\HR\Models\PayrollRun;
use App\Domain\HR\Models\SalaryAdvanceRecovery;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undo an approval to correct the payroll, as long as nobody has been paid yet: the
 * approval entry is reversed and the advance installments are given back.
 */
class ReopenPayrollAction
{
    public function __construct(private readonly JournalService $journal) {}

    public function handle(PayrollRun $run, User $user, string $reason): PayrollRun
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter the reason.']);
        }

        return DB::transaction(function () use ($run, $user, $reason): PayrollRun {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($run->status !== PayrollStatus::Approved || $run->payslips()->whereNotNull('paid_at')->exists() || $run->epf_etf_paid_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Only an approved payroll with nothing paid yet can be reopened.']);
            }

            $recoveries = SalaryAdvanceRecovery::query()
                ->whereIn('payslip_id', $run->payslips()->select('id'))
                ->with('advance')
                ->lockForUpdate()
                ->get();

            foreach ($recoveries as $recovery) {
                $advance = $recovery->advance;
                $advance->forceFill([
                    'recovered_amount' => (string) Money::of($advance->recovered_amount)->minus($recovery->amount),
                    'status' => SalaryAdvanceStatus::Active,
                ])->save();
                $recovery->delete();
            }

            $entry = $this->journal->find($run, ApprovePayrollAction::EVENT);

            if ($entry !== null) {
                $this->journal->reverse($entry, today(), "Payroll {$run->label()} reopened: {$reason}", $user->id, $run, 'payroll.reopened');
            }

            $run->forceFill([
                'status' => PayrollStatus::Calculated,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            activity()->performedOn($run)->causedBy($user)->event('reopened')->withProperties(['reason' => $reason])->log('Payroll reopened');

            return $run;
        });
    }
}
