<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Finance\Enums\PaidFrom;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Services\BankBook;
use App\Domain\Finance\Services\DrawerCash;
use App\Domain\Finance\Services\JournalService;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancel an expense entered by mistake: the journal entry is reversed and the money
 * goes back where it came from (drawer pay in while that drawer is still open, bank
 * deposit). The expense stays in the list, marked cancelled.
 */
class CancelExpenseAction
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly BankBook $bankBook,
        private readonly DrawerCash $drawerCash,
    ) {}

    public function handle(Expense $expense, User $user, string $reason): Expense
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter the reason.']);
        }

        return DB::transaction(function () use ($expense, $user, $reason): Expense {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->isCancelled()) {
                throw ValidationException::withMessages(['reason' => "{$expense->number} is already cancelled."]);
            }

            $description = "Expense {$expense->number} cancelled: {$reason}";

            if ($expense->paid_from === PaidFrom::CashDrawer && $expense->drawer_session_id !== null) {
                $this->drawerCash->putBack(Money::of($expense->amount), $description, $expense, $user->id, $expense->drawer_session_id);
            }

            if ($expense->paid_from === PaidFrom::Bank && $expense->bank_account_id !== null) {
                $this->bankBook->record($expense->bankAccount()->firstOrFail(), BankTransactionType::Deposit, $expense->amount, today(), $description, $expense->reference, $expense, $user->id);
            }

            $entry = $this->journal->find($expense, 'expense.recorded');

            if ($entry !== null) {
                $this->journal->reverse($entry, today(), $description, $user->id, $expense, 'expense.cancelled');
            }

            $expense->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => mb_substr($reason, 0, 255),
            ])->save();

            return $expense;
        });
    }
}
