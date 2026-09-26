<?php

namespace App\Domain\Finance\Enums;

enum BankAccountType: string
{
    case Current = 'current';
    case Savings = 'savings';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Current',
            self::Savings => 'Savings',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type) => [$type->value => $type->label()])->all();
    }
}
