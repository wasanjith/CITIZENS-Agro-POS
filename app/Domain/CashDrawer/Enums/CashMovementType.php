<?php

namespace App\Domain\CashDrawer\Enums;

enum CashMovementType: string
{
    case PayIn = 'pay_in';
    case PayOut = 'pay_out';
    case SafeDrop = 'safe_drop';
    case BankDeposit = 'bank_deposit';

    public function label(): string
    {
        return match ($this) {
            self::PayIn => 'Pay in',
            self::PayOut => 'Pay out',
            self::SafeDrop => 'Safe drop',
            self::BankDeposit => 'Bank deposit',
        };
    }

    /**
     * +1 when cash comes into the drawer, −1 when it leaves.
     */
    public function sign(): int
    {
        return $this === self::PayIn ? 1 : -1;
    }

    /**
     * Types entered on the drawer page. Bank deposits are recorded from Finance (Phase 5).
     *
     * @return array<string, string>
     */
    public static function drawerOptions(): array
    {
        return collect([self::PayIn, self::PayOut, self::SafeDrop])->mapWithKeys(fn (self $type) => [$type->value => $type->label()])->all();
    }
}
