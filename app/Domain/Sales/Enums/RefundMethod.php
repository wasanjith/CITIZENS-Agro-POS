<?php

namespace App\Domain\Sales\Enums;

/**
 * How a sale return is paid back: cash from the cashier's drawer, or a credit on the
 * customer's account (lowers what they owe on the invoice first, then their balance).
 */
enum RefundMethod: string
{
    case Cash = 'cash';
    case Account = 'account';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash from the drawer',
            self::Account => 'Credit to the customer account',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $method) => [$method->value => $method->label()])->all();
    }
}
