<?php

namespace App\Domain\Sales\Enums;

enum SaleStatus: string
{
    case OnHold = 'on_hold';
    case Invoiced = 'invoiced';
    case Settled = 'settled';
    case Void = 'void';
    case PartiallyReturned = 'partially_returned';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::OnHold => 'On hold',
            self::Invoiced => 'Waiting for settlement',
            self::Settled => 'Settled',
            self::Void => 'Void',
            self::PartiallyReturned => 'Partly returned',
            self::Returned => 'Returned',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OnHold => 'gray',
            self::Invoiced => 'amber',
            self::Settled => 'green',
            self::Void => 'red',
            self::PartiallyReturned, self::Returned => 'purple',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [$status->value => $status->label()])->all();
    }
}
