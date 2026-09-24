<?php

namespace App\Domain\System\Enums;

use Carbon\CarbonInterface;

enum SequenceResetPeriod: string
{
    case Never = 'never';
    case Daily = 'daily';
    case Yearly = 'yearly';

    /**
     * The key identifying the current period; a change of key restarts numbering at 1.
     */
    public function periodKey(CarbonInterface $date): ?string
    {
        return match ($this) {
            self::Never => null,
            self::Daily => $date->format('Y-m-d'),
            self::Yearly => $date->format('Y'),
        };
    }
}
