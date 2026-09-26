<?php

namespace App\Domain\Finance\Enums;

enum ChequeDirection: string
{
    case Received = 'received';
    case Issued = 'issued';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Issued => 'Issued',
        };
    }
}
