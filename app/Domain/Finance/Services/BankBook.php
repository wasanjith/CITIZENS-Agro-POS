<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\BankTransaction;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes bank transactions (the bank book). The journal entry that goes with each one
 * is posted by the caller.
 */
class BankBook
{
    public function record(
        BankAccount $bank,
        BankTransactionType $type,
        BigDecimal|string $amount,
        DateTimeInterface $date,
        string $description,
        ?string $reference = null,
        ?Model $related = null,
        ?int $userId = null,
    ): BankTransaction {
        return BankTransaction::create([
            'bank_account_id' => $bank->id,
            'date' => $date,
            'type' => $type,
            'amount' => (string) Money::of($amount)->abs(),
            'reference' => $reference !== null ? mb_substr($reference, 0, 100) : null,
            'description' => mb_substr($description, 0, 255),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'created_by' => $userId ?? auth()->id(),
        ]);
    }

    /**
     * The account card and bank-transfer payments at the cashier land in, if one is set.
     */
    public function receivingAccount(): ?BankAccount
    {
        return BankAccount::query()->active()->where('receives_card_payments', true)->orderBy('id')->first();
    }
}
