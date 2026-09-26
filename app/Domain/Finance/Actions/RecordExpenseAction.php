<?php

namespace App\Domain\Finance\Actions;

use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Finance\Enums\PaidFrom;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Services\BankBook;
use App\Domain\Finance\Services\DrawerCash;
use App\Domain\Finance\Services\JournalService;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Record a shop expense (with an optional photo of the bill). Petty cash comes out of
 * the open drawer at the main cashier (a pay out); safe and bank money leave their
 * account. Journal: Dr the category's expense account, Cr Drawer / Safe / Bank.
 */
class RecordExpenseAction
{
    public function __construct(
        private readonly DocumentNumber $numbers,
        private readonly JournalService $journal,
        private readonly BankBook $bankBook,
        private readonly DrawerCash $drawerCash,
    ) {}

    /**
     * @param  array{date: string, expense_category_id: int|string, amount: string, paid_from: string, bank_account_id?: int|string|null, payee?: string|null, reference?: string|null, note?: string|null}  $data
     */
    public function handle(array $data, User $user, ?UploadedFile $receipt = null): Expense
    {
        $amount = Money::of($data['amount']);
        $from = PaidFrom::tryFrom($data['paid_from']);
        $category = ExpenseCategory::query()->active()->find((int) $data['expense_category_id']);

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount spent.']);
        }

        if ($category === null) {
            throw ValidationException::withMessages(['expense_category_id' => 'Choose what the money was spent on.']);
        }

        if (! in_array($from, [PaidFrom::CashDrawer, PaidFrom::Safe, PaidFrom::Bank], true)) {
            throw ValidationException::withMessages(['paid_from' => 'Choose where the money came from.']);
        }

        $bank = $from === PaidFrom::Bank ? BankAccount::query()->active()->find((int) ($data['bank_account_id'] ?? 0)) : null;

        if ($from === PaidFrom::Bank && $bank === null) {
            throw ValidationException::withMessages(['bank_account_id' => 'Choose the bank account.']);
        }

        $path = $receipt?->store('expense-receipts', 'local');

        try {
            return DB::transaction(function () use ($data, $user, $amount, $from, $category, $bank, $path): Expense {
                $date = Carbon::parse($data['date']);
                $this->numbers->ensure('EXP', 'EXP-{Y}-', 5);

                $expense = Expense::create([
                    'number' => $this->numbers->next('EXP'),
                    'date' => $date,
                    'expense_category_id' => $category->id,
                    'amount' => (string) $amount,
                    'paid_from' => $from,
                    'bank_account_id' => $bank?->id,
                    'payee' => filled($data['payee'] ?? null) ? mb_substr((string) $data['payee'], 0, 150) : null,
                    'reference' => filled($data['reference'] ?? null) ? mb_substr((string) $data['reference'], 0, 100) : null,
                    'note' => filled($data['note'] ?? null) ? mb_substr((string) $data['note'], 0, 255) : null,
                    'receipt_path' => $path ?: null,
                    'created_by' => $user->id,
                ]);

                $description = "Expense {$expense->number}: {$category->name}".($expense->payee ? " ({$expense->payee})" : '');

                if ($from === PaidFrom::CashDrawer) {
                    $movement = $this->drawerCash->takeOut($amount, CashMovementType::PayOut, $description, $expense, $user->id);
                    $expense->forceFill(['drawer_session_id' => $movement->drawer_session_id])->save();
                    $credit = SystemAccount::CashDrawer;
                } elseif ($bank !== null) {
                    $this->bankBook->record($bank, BankTransactionType::Withdrawal, $amount, $date, $description, $expense->reference, $expense, $user->id);
                    $credit = $bank->account_id;
                } else {
                    $credit = SystemAccount::Safe;
                }

                $this->journal->post($description, $date, [
                    ['account' => $category->account_id, 'debit' => $amount],
                    ['account' => $credit, 'credit' => $amount],
                ], $expense, 'expense.recorded', $user->id);

                return $expense;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }
}
