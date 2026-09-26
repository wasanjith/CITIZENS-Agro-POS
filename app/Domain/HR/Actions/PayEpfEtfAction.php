<?php

namespace App\Domain\HR\Actions;

use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Services\JournalService;
use App\Domain\HR\Enums\PayrollStatus;
use App\Domain\HR\Models\PayrollRun;
use App\Domain\HR\Services\HrCash;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record the month's EPF (employee 8 % + employer 12 %) and ETF (3 %) sent to the funds,
 * from the cash at home or a bank account.
 * Journal: Dr EPF payable, Dr ETF payable, Cr Cash at home / Bank.
 */
class PayEpfEtfAction
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly HrCash $cash,
    ) {}

    /**
     * @param  array{paid_from: string, bank_account_id?: int|string|null, date: string, reference?: string|null}  $data
     */
    public function handle(PayrollRun $run, array $data, User $user): PayrollRun
    {
        [$from, $bank] = $this->cash->resolveSource($data['paid_from'], $data['bank_account_id'] ?? null, withDrawer: false);
        $date = Carbon::parse($data['date'])->startOfDay();
        $reference = filled($data['reference'] ?? null) ? mb_substr((string) $data['reference'], 0, 100) : null;

        return DB::transaction(function () use ($run, $from, $bank, $date, $reference, $user): PayrollRun {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! in_array($run->status, [PayrollStatus::Approved, PayrollStatus::Paid], true)) {
                throw ValidationException::withMessages(['paid_from' => 'Approve the payroll first.']);
            }

            if ($run->epf_etf_paid_at !== null) {
                throw ValidationException::withMessages(['paid_from' => "EPF and ETF for {$run->label()} are already recorded as paid."]);
            }

            $epf = Money::of($run->total_epf_employee)->plus($run->total_epf_employer);
            $etf = Money::of($run->total_etf);
            $total = $epf->plus($etf);

            if (! $total->isPositive()) {
                throw ValidationException::withMessages(['paid_from' => 'There is no EPF or ETF for this month.']);
            }

            $description = "EPF and ETF for {$run->label()}";
            $paid = $this->cash->payOut($from, $bank, $total, $date, $description, $reference, $run, $user->id);

            $this->journal->post($description, $date, [
                ['account' => SystemAccount::EpfPayable, 'debit' => $epf],
                ['account' => SystemAccount::EtfPayable, 'debit' => $etf],
                ['account' => $paid['account'], 'credit' => $total],
            ], $run, 'epf_etf.paid', $user->id);

            $run->forceFill([
                'epf_etf_paid_at' => $date->isToday() ? now() : $date,
                'epf_etf_paid_by' => $user->id,
                'epf_etf_reference' => $reference,
            ])->save();

            return $run;
        });
    }
}
