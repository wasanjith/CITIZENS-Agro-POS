<?php

namespace App\Domain\HR\Actions;

use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Services\JournalService;
use App\Domain\HR\Enums\SalaryAdvanceStatus;
use App\Domain\HR\Models\SalaryAdvance;
use App\Domain\HR\Services\HrCash;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancel an advance entered by mistake (or returned in full before any payslip took
 * an installment): the money goes back where it came from (drawer cash only while that
 * drawer is still open, else to the cash at home). Dr that account, Cr Staff advances.
 */
class CancelSalaryAdvanceAction
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly HrCash $cash,
    ) {}

    public function handle(SalaryAdvance $advance, User $user, string $reason): SalaryAdvance
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter the reason.']);
        }

        return DB::transaction(function () use ($advance, $user, $reason): SalaryAdvance {
            $advance = SalaryAdvance::query()->lockForUpdate()->findOrFail($advance->id);

            if ($advance->status !== SalaryAdvanceStatus::Active || Money::of($advance->recovered_amount)->isPositive() || $advance->recoveries()->exists()) {
                throw ValidationException::withMessages(['reason' => "{$advance->number} can no longer be cancelled: a payslip has already recovered part of it."]);
            }

            $description = "Salary advance {$advance->number} cancelled: {$reason}";
            $amount = Money::of($advance->amount);
            $account = $this->cash->putBack($advance->paid_from, $advance->bankAccount, $advance->drawer_session_id, $amount, $description, $advance, $user->id);

            $this->journal->post($description, today(), [
                ['account' => $account, 'debit' => $amount],
                ['account' => SystemAccount::StaffAdvances, 'credit' => $amount],
            ], $advance, 'advance.cancelled', $user->id);

            $advance->forceFill([
                'status' => SalaryAdvanceStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => mb_substr($reason, 0, 255),
            ])->save();

            return $advance;
        });
    }
}
