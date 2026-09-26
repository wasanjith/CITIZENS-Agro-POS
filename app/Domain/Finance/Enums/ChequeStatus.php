<?php

namespace App\Domain\Finance\Enums;

/**
 * Received: PENDING (in hand) → DEPOSITED → CLEARED, or BOUNCED.
 * Issued:   PENDING (given to the supplier) → CLEARED, or BOUNCED / CANCELLED.
 */
enum ChequeStatus: string
{
    case Pending = 'pending';
    case Deposited = 'deposited';
    case Cleared = 'cleared';
    case Bounced = 'bounced';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Deposited => 'Deposited',
            self::Cleared => 'Cleared',
            self::Bounced => 'Bounced',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Deposited => 'blue',
            self::Cleared => 'green',
            self::Bounced => 'red',
            self::Cancelled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Deposited], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [$status->value => $status->label()])->all();
    }
}
