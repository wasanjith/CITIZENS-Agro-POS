<?php

namespace App\Domain\Sales\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Credit = 'credit';
    case Split = 'split';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Card => 'Card',
            self::BankTransfer => 'Bank transfer',
            self::Cheque => 'Cheque',
            self::Credit => 'Credit',
            self::Split => 'Split',
        };
    }

    /**
     * Methods a counter can choose. Credit needs a customer on the bill; split payments
     * come with cheques and bank accounts (Phase 5).
     *
     * @return list<self>
     */
    public static function counterMethods(): array
    {
        return [self::Cash, self::Card, self::BankTransfer, self::Cheque, self::Credit];
    }

    /**
     * How a customer can pay towards their credit account.
     *
     * @return list<self>
     */
    public static function customerPaymentMethods(): array
    {
        return [self::Cash, self::Card, self::BankTransfer, self::Cheque];
    }

    /**
     * @return array<string, string>
     */
    public static function customerPaymentOptions(): array
    {
        return collect(self::customerPaymentMethods())->mapWithKeys(fn (self $method) => [$method->value => $method->label()])->all();
    }

    /**
     * @return array<string, string>
     */
    public static function counterOptions(): array
    {
        return collect(self::counterMethods())->mapWithKeys(fn (self $method) => [$method->value => $method->label()])->all();
    }

    /**
     * Everything except cash is confirmed by the cashier (reference, approval).
     */
    public function needsConfirmation(): bool
    {
        return $this !== self::Cash;
    }

    /**
     * Card, bank transfer and cheque need a slip / transfer / cheque number.
     */
    public function needsReference(): bool
    {
        return in_array($this, [self::Card, self::BankTransfer, self::Cheque], true);
    }
}
