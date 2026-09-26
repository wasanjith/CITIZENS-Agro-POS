<?php

namespace App\Domain\HR\Actions;

use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Services\JournalService;
use App\Domain\HR\Enums\SalaryAdvanceStatus;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\SalaryAdvance;
use App\Domain\HR\Services\HrCash;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lend an employee money against their salary. It is recovered from the next payslips
 * in equal installments (the last one may be smaller).
 * Journal: Dr Staff advances, Cr Drawer / Cash at home / Bank.
 */
class GiveSalaryAdvanceAction
{
    public function __construct(
        private readonly DocumentNumber $numbers,
        private readonly JournalService $journal,
        private readonly HrCash $cash,
    ) {}

    /**
     * @param  array{employee_id: int|string, date: string, amount: string, installments: int|string, paid_from: string, bank_account_id?: int|string|null, note?: string|null}  $data
     */
    public function handle(array $data, User $user): SalaryAdvance
    {
        $employee = Employee::query()->active()->find((int) $data['employee_id']);
        $amount = Money::of($data['amount']);
        $installments = max(1, (int) $data['installments']);

        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'Choose the employee.']);
        }

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount.']);
        }

        [$from, $bank] = $this->cash->resolveSource($data['paid_from'], $data['bank_account_id'] ?? null);

        return DB::transaction(function () use ($data, $user, $employee, $amount, $installments, $from, $bank): SalaryAdvance {
            $date = Carbon::parse($data['date'])->startOfDay();
            $this->numbers->ensure('ADV', 'ADV-{Y}-', 4);

            $advance = SalaryAdvance::create([
                'number' => $this->numbers->next('ADV'),
                'employee_id' => $employee->id,
                'date' => $date,
                'amount' => (string) $amount,
                'installments' => $installments,
                'installment_amount' => (string) $amount->dividedBy($installments, 2, RoundingMode::Up),
                'recovered_amount' => '0.00',
                'status' => SalaryAdvanceStatus::Active,
                'paid_from' => $from,
                'bank_account_id' => $bank?->id,
                'note' => filled($data['note'] ?? null) ? mb_substr((string) $data['note'], 0, 255) : null,
                'created_by' => $user->id,
            ]);

            $description = "Salary advance {$advance->number} to {$employee->full_name}";
            $paid = $this->cash->payOut($from, $bank, $amount, $date, $description, $advance->number, $advance, $user->id);
            $advance->forceFill(['drawer_session_id' => $paid['drawer_session_id']])->save();

            $this->journal->post($description, $date, [
                ['account' => SystemAccount::StaffAdvances, 'debit' => $amount],
                ['account' => $paid['account'], 'credit' => $amount],
            ], $advance, 'advance.given', $user->id);

            return $advance;
        });
    }
}
