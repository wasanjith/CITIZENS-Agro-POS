<?php

namespace App\Domain\Inventory\Enums;

/**
 * counting → review → posted (or cancelled before posting).
 */
enum StocktakeStatus: string
{
    case Open = 'open';
    case Counting = 'counting';
    case Review = 'review';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Counting => 'Counting',
            self::Review => 'Review',
            self::Posted => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open, self::Counting => 'blue',
            self::Review => 'amber',
            self::Posted => 'green',
            self::Cancelled => 'gray',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Posted, self::Cancelled], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [$status->value => $status->label()])->all();
    }
}
