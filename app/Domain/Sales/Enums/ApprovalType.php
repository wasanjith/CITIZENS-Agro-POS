<?php

namespace App\Domain\Sales\Enums;

enum ApprovalType: string
{
    case Discount = 'discount';
    case PriceOverride = 'price_override';
    case Void = 'void';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Discount => 'Discount',
            self::PriceOverride => 'Price change',
            self::Void => 'Void',
            self::Refund => 'Refund',
        };
    }
}
