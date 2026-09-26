<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\ChequeDirection;
use App\Domain\Finance\Enums\ChequeStatus;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\Cheque;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A received cheque goes to the bank. The money only counts once it clears, so no
 * journal entry yet (it stays under Cheques in hand).
 */
class DepositChequeAction
{
    public function handle(Cheque $cheque, BankAccount $bank, Carbon $date, User $user): Cheque
    {
        return DB::transaction(function () use ($cheque, $bank, $date, $user): Cheque {
            $cheque = Cheque::query()->lockForUpdate()->findOrFail($cheque->id);

            if ($cheque->direction !== ChequeDirection::Received || $cheque->status !== ChequeStatus::Pending) {
                throw ValidationException::withMessages(['cheque' => "Cheque {$cheque->number} is {$cheque->status->label()}; it cannot be deposited."]);
            }

            if ($date->lt($cheque->cheque_date)) {
                throw ValidationException::withMessages(['date' => "This cheque is dated {$cheque->cheque_date->format('Y-m-d')}. It can be deposited from that day."]);
            }

            $cheque->bank_account_id = $bank->id;
            $cheque->deposited_on = $date;
            $cheque->transition(ChequeStatus::Deposited, $user->id, "Deposited to {$bank->displayName()}");
            $cheque->save();

            return $cheque;
        });
    }
}
