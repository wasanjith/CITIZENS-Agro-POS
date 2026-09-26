<?php

namespace App\Domain\Reports\Support;

/**
 * Sections of the Reports page.
 */
enum ReportGroup: string
{
    case Sales = 'sales';
    case LossPrevention = 'loss_prevention';
    case Profit = 'profit';
    case Inventory = 'inventory';
    case Purchasing = 'purchasing';
    case Customers = 'customers';
    case Finance = 'finance';
    case Hr = 'hr';
    case Audit = 'audit';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales',
            self::LossPrevention => 'Loss prevention',
            self::Profit => 'Profit',
            self::Inventory => 'Inventory',
            self::Purchasing => 'Purchasing',
            self::Customers => 'Customers',
            self::Finance => 'Cash & finance',
            self::Hr => 'HR',
            self::Audit => 'Audit',
        };
    }
}
