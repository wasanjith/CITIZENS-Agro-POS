<?php

namespace App\Domain\Sales\Enums;

enum CounterEventType: string
{
    case ItemAdded = 'item_added';
    case QtyChanged = 'qty_changed';
    case ItemRemoved = 'item_removed';
    case CartCleared = 'cart_cleared';
    case CustomerSet = 'customer_set';
    case Tendered = 'tendered';
    case Printed = 'printed';
    case Reprinted = 'reprinted';
    case Settled = 'settled';
    case Voided = 'voided';
    case Held = 'held';
    case Recalled = 'recalled';

    public function label(): string
    {
        return match ($this) {
            self::ItemAdded => 'added',
            self::QtyChanged => 'changed qty',
            self::ItemRemoved => 'removed',
            self::CartCleared => 'cleared the bill',
            self::CustomerSet => 'set customer',
            self::Tendered => 'tendered',
            self::Printed => 'printed',
            self::Reprinted => 'reprinted',
            self::Settled => 'settled',
            self::Voided => 'voided',
            self::Held => 'held the bill',
            self::Recalled => 'recalled a bill',
        };
    }

    /**
     * Shown in red in the Live Billing ticker (loss prevention).
     */
    public function isAlert(): bool
    {
        return in_array($this, [self::ItemRemoved, self::CartCleared, self::Reprinted, self::Voided], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type) => [$type->value => ucfirst($type->label())])->all();
    }
}
