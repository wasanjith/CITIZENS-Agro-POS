<?php

namespace App\Domain\Sales\Enums;

enum QuotationStatus: string
{
    case Open = 'open';
    case Converted = 'converted';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Converted => 'Billed',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'blue',
            self::Converted => 'green',
            self::Expired => 'gray',
            self::Cancelled => 'red',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [$status->value => $status->label()])->all();
    }
}
