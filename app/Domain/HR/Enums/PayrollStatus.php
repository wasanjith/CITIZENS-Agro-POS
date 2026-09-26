<?php

namespace App\Domain\HR\Enums;

/**
 * Calculated (can be edited and recalculated) → Approved (posted to the accounts)
 * → Paid (every payslip paid).
 */
enum PayrollStatus: string
{
    case Calculated = 'calculated';
    case Approved = 'approved';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Calculated => 'Calculated (not approved)',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Calculated => 'amber',
            self::Approved => 'blue',
            self::Paid => 'green',
        };
    }
}
