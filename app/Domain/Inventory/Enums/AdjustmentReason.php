<?php

namespace App\Domain\Inventory\Enums;

enum AdjustmentReason: string
{
    case Damage = 'damage';
    case Expired = 'expired';
    case Lost = 'lost';
    case Found = 'found';
    case Correction = 'correction';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damage => 'Damaged',
            self::Expired => 'Expired',
            self::Lost => 'Lost / missing',
            self::Found => 'Found',
            self::Correction => 'Correction',
            self::Other => 'Other',
        };
    }

    /**
     * Movement type written for a line of this reason (damaged stock is reported separately).
     */
    public function movementType(bool $isIncrease): MovementType
    {
        if ($isIncrease) {
            return MovementType::AdjustIn;
        }

        return $this === self::Damage ? MovementType::Damage : MovementType::AdjustOut;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $reason) => [$reason->value => $reason->label()])->all();
    }
}
