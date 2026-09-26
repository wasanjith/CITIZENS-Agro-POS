<?php

namespace App\Domain\HR\Enums;

enum SalaryAdvanceStatus: string
{
    case Active = 'active';
    case Recovered = 'recovered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Being recovered',
            self::Recovered => 'Recovered',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'amber',
            self::Recovered => 'green',
            self::Cancelled => 'gray',
        };
    }
}
