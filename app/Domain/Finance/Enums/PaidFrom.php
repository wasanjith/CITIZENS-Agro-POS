<?php

namespace App\Domain\Finance\Enums;

/**
 * Where the money for an expense or a supplier payment comes from.
 */
enum PaidFrom: string
{
    case CashDrawer = 'cash_drawer';
    case Safe = 'safe';
    case Bank = 'bank';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::CashDrawer => 'Cash from the drawer',
            self::Safe => 'Cash from home',
            self::Bank => 'Bank transfer',
            self::Cheque => 'Cheque',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function expenseOptions(): array
    {
        return collect([self::CashDrawer, self::Safe, self::Bank])->mapWithKeys(fn (self $from) => [$from->value => $from->label()])->all();
    }

    /**
     * @return array<string, string>
     */
    public static function supplierOptions(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $from) => [$from->value => $from->label()])->all();
    }
}
