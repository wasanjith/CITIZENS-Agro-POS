<?php

namespace App\Domain\Customers\Enums;

enum CustomerLedgerType: string
{
    case Opening = 'opening';
    case Sale = 'sale';
    case Payment = 'payment';
    case Return = 'return';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening balance',
            self::Sale => 'Credit sale',
            self::Payment => 'Payment',
            self::Return => 'Return',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * Statement label in Sinhala.
     */
    public function labelSi(): string
    {
        return match ($this) {
            self::Opening => 'ආරම්භක ශේෂය',
            self::Sale => 'ණය විකුණුම',
            self::Payment => 'ගෙවීම',
            self::Return => 'ආපසු භාරදීම',
            self::Adjustment => 'ගැලපීම',
        };
    }
}
