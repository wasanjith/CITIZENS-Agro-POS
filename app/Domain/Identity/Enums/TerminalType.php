<?php

namespace App\Domain\Identity\Enums;

enum TerminalType: string
{
    case MainCashier = 'main_cashier';
    case Counter = 'counter';

    public function label(): string
    {
        return match ($this) {
            self::MainCashier => 'Main cashier',
            self::Counter => 'Counter',
        };
    }
}
