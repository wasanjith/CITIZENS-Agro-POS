<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\BankTransaction;
use App\Domain\Finance\Services\BankBook;
use App\Domain\Finance\Services\JournalService;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A charge the bank took (Dr Bank charges, Cr Bank) or interest it paid
 * (Dr Bank, Cr Bank interest), as seen on the statement.
 */
class RecordBankChargeAction
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly BankBook $bankBook,
    ) {}

    /**
     * @param  array{type: string, amount: string, date: string, description?: string|null, reference?: string|null}  $data
     */
    public function handle(BankAccount $bank, array $data, User $user): BankTransaction
    {
        $type = BankTransactionType::tryFrom($data['type']);
        $amount = Money::of($data['amount']);

        if (! in_array($type, [BankTransactionType::Charge, BankTransactionType::Interest], true)) {
            throw ValidationException::withMessages(['type' => 'Choose bank charge or interest.']);
        }

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above 0.']);
        }

        return DB::transaction(function () use ($bank, $data, $type, $amount, $user): BankTransaction {
            $date = Carbon::parse($data['date']);
            $description = trim((string) ($data['description'] ?? '')) ?: $type->label();
            $transaction = $this->bankBook->record($bank, $type, $amount, $date, $description, $data['reference'] ?? null, null, $user->id);

            $other = $type === BankTransactionType::Charge ? SystemAccount::BankCharges : SystemAccount::InterestIncome;
            $signed = $type === BankTransactionType::Charge ? $amount->negated() : $amount;

            $this->journal->post("{$type->label()}: {$bank->displayName()} {$description}", $date, [
                ['account' => $bank->account_id, 'debit' => $signed],
                ['account' => $other, 'credit' => $signed],
            ], $transaction, 'bank.'.$type->value, $user->id);

            return $transaction;
        });
    }
}
