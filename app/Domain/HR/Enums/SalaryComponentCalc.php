<?php

namespace App\Domain\HR\Enums;

enum SalaryComponentCalc: string
{
    case Fixed = 'fixed';
    case PercentBasic = 'percent_basic';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount (Rs.)',
            self::PercentBasic => '% of basic salary',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $calc) => [$calc->value => $calc->label()])->all();
    }
}
