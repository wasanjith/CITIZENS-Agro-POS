<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\BankReconciliation;
use App\Domain\Finance\Models\BankTransaction;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tick the bank-book transactions that appear on a bank statement. They are marked
 * reconciled; cleared balance = opening balance + every reconciled transaction, and
 * the difference to the statement balance is kept with the reconciliation.
 */
class ReconcileBankAccountAction
{
    /**
     * @param  list<int>  $transactionIds
     */
    public function handle(BankAccount $bank, Carbon $statementDate, string $statementBalance, array $transactionIds, User $user, ?string $note = null): BankReconciliation
    {
        return DB::transaction(function () use ($bank, $statementDate, $statementBalance, $transactionIds, $user, $note): BankReconciliation {
            $bank = BankAccount::query()->lockForUpdate()->findOrFail($bank->id);

            $transactions = BankTransaction::query()
                ->where('bank_account_id', $bank->id)
                ->whereIn('id', $transactionIds)
                ->whereNull('reconciled_at')
                ->lockForUpdate()
                ->get();

            if ($transactions->count() !== count(array_unique($transactionIds))) {
                throw ValidationException::withMessages(['transactions' => 'Some ticked lines are not open transactions of this account. Reload the page.']);
            }

            if ($transactions->contains(fn (BankTransaction $transaction) => $transaction->date->gt($statementDate))) {
                throw ValidationException::withMessages(['transactions' => 'Only transactions up to the statement date can be ticked.']);
            }

            $reconciliation = BankReconciliation::create([
                'bank_account_id' => $bank->id,
                'statement_date' => $statementDate,
                'statement_balance' => (string) Money::of($statementBalance),
                'cleared_balance' => '0',
                'difference' => '0',
                'note' => $note !== null ? mb_substr($note, 0, 255) : null,
                'created_by' => $user->id,
            ]);

            BankTransaction::query()->whereKey($transactions->modelKeys())->update([
                'reconciled_at' => now(),
                'bank_reconciliation_id' => $reconciliation->id,
            ]);

            $cleared = Money::of($bank->opening_balance)->plus($bank->movementTotal(reconciledOnly: true));

            $reconciliation->forceFill([
                'cleared_balance' => (string) $cleared,
                'difference' => (string) Money::of($statementBalance)->minus($cleared),
            ])->save();

            return $reconciliation;
        });
    }
}
