<?php

namespace App\Domain\CashDrawer\Enums;

enum DrawerCloseReason: string
{
    case EndOfDay = 'end_of_day';
    case Handover = 'handover';

    public function label(): string
    {
        return match ($this) {
            self::EndOfDay => 'End of day',
            self::Handover => 'Handover',
        };
    }
}
