<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Finance\Services\JournalService;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a bank account. A new account gets its own ledger account (15xx)
 * and its opening balance is posted against Opening balances; changing the opening
 * balance later posts the difference. Only one account receives card payments.
 */
class SaveBankAccountAction
{
    public function __construct(
        private readonly ChartOfAccounts $accounts,
        private readonly JournalService $journal,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $user, ?BankAccount $bank = null): BankAccount
    {
        return DB::transaction(function () use ($data, $user, $bank): BankAccount {
            $name = trim("{$data['bank_name']} {$data['account_no']}");

            if ($bank === null) {
                $ledger = $this->accounts->createBankLedgerAccount("Bank: {$name}");
                $bank = BankAccount::create([...$data, 'account_id' => $ledger->id]);
                $previousOpening = Money::zero();
            } else {
                $previousOpening = Money::of($bank->opening_balance);
                $bank->update($data);
                $bank->account()->firstOrFail()->update(['name' => mb_substr("Bank: {$name}", 0, 120), 'is_active' => $bank->is_active]);
            }

            if ($bank->receives_card_payments) {
                BankAccount::query()->whereKeyNot($bank->id)->update(['receives_card_payments' => false]);
            }

            $difference = Money::of($bank->opening_balance)->minus($previousOpening);

            $this->journal->post("Opening balance of {$bank->displayName()}", $bank->opening_date, [
                ['account' => $bank->account_id, 'debit' => $difference],
                ['account' => SystemAccount::OpeningBalanceEquity, 'credit' => $difference],
            ], $bank, 'bank.opening', $user->id);

            return $bank->refresh();
        });
    }
}
