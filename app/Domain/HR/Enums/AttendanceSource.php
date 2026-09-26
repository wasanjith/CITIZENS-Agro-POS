<?php

namespace App\Domain\HR\Enums;

enum AttendanceSource: string
{
    case Pos = 'pos';
    case Manual = 'manual';
    case Leave = 'leave';

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'POS sign-in',
            self::Manual => 'Entered by hand',
            self::Leave => 'Approved leave',
        };
    }
}
