<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Finance\Enums\ChequeDirection;
use App\Domain\Finance\Enums\ChequeStatus;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Finance\Services\BankBook;
use App\Domain\Finance\Services\JournalService;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The bank cleared a cheque.
 *  - Received (deposited): Dr Bank, Cr Cheques in hand + a bank deposit.
 *  - Issued (pending):     Dr Cheques issued, Cr Bank + a bank withdrawal.
 */
class ClearChequeAction
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly BankBook $bankBook,
    ) {}

    public function handle(Cheque $cheque, Carbon $date, User $user): Cheque
    {
        return DB::transaction(function () use ($cheque, $date, $user): Cheque {
            $cheque = Cheque::query()->lockForUpdate()->findOrFail($cheque->id);
            $received = $cheque->direction === ChequeDirection::Received;
            $expected = $received ? ChequeStatus::Deposited : ChequeStatus::Pending;

            if ($cheque->status !== $expected || $cheque->bank_account_id === null) {
                throw ValidationException::withMessages(['cheque' => $received
                    ? "Cheque {$cheque->number} must be deposited before it can clear."
                    : "Cheque {$cheque->number} is {$cheque->status->label()}; it cannot clear."]);
            }

            $bank = $cheque->bankAccount()->firstOrFail();
            $description = ($received ? 'Cheque received ' : 'Cheque issued ')."{$cheque->number}: {$cheque->partyLabel()}";
            $this->bankBook->record($bank, $received ? BankTransactionType::Deposit : BankTransactionType::Withdrawal, $cheque->amount, $date, $description, $cheque->number, $cheque, $user->id);

            $this->journal->post("{$description} cleared", $date, $received
                ? [
                    ['account' => $bank->account_id, 'debit' => $cheque->amount],
                    ['account' => SystemAccount::ChequesInHand, 'credit' => $cheque->amount],
                ]
                : [
                    ['account' => SystemAccount::ChequesIssued, 'debit' => $cheque->amount],
                    ['account' => $bank->account_id, 'credit' => $cheque->amount],
                ], $cheque, 'cheque.cleared', $user->id);

            $cheque->cleared_on = $date;
            $cheque->transition(ChequeStatus::Cleared, $user->id);
            $cheque->save();

            return $cheque;
        });
    }
}
