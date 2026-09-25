<?php

namespace App\Domain\Purchasing\Enums;

enum SupplierLedgerType: string
{
    case GoodsReceipt = 'grn';
    case Return = 'return';
    case Payment = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::GoodsReceipt => 'Goods received',
            self::Return => 'Return to supplier',
            self::Payment => 'Payment',
        };
    }
}
