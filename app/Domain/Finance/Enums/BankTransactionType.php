<?php

namespace App\Domain\Finance\Enums;

enum BankTransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Charge = 'charge';
    case Interest = 'interest';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
            self::TransferIn => 'Transfer in',
            self::TransferOut => 'Transfer out',
            self::Charge => 'Bank charge',
            self::Interest => 'Interest',
        };
    }

    /**
     * +1 when money comes into the account, −1 when it leaves.
     */
    public function sign(): int
    {
        return in_array($this, [self::Deposit, self::TransferIn, self::Interest], true) ? 1 : -1;
    }

    /**
     * @return list<string>
     */
    public static function inflowValues(): array
    {
        return [self::Deposit->value, self::TransferIn->value, self::Interest->value];
    }
}
