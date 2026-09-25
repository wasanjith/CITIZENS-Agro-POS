<?php

namespace App\Domain\Inventory\Notifications;

use App\Domain\System\Notifications\AppNotification;

/**
 * Daily summary: products at or below their reorder level and batches about to expire.
 */
class LowStockAndExpiryAlert extends AppNotification
{
    public function __construct(
        public readonly int $lowStockCount,
        public readonly int $expiringCount,
        public readonly int $expiryDays,
    ) {}

    public function title(): string
    {
        return 'Stock alert';
    }

    public function message(): string
    {
        $parts = [];

        if ($this->lowStockCount > 0) {
            $parts[] = "{$this->lowStockCount} ".str('product')->plural($this->lowStockCount).' at or below the reorder level';
        }

        if ($this->expiringCount > 0) {
            $parts[] = "{$this->expiringCount} ".str('batch')->plural($this->expiringCount)." expiring within {$this->expiryDays} days";
        }

        return ucfirst(implode(' and ', $parts)).'.';
    }

    public function url(): string
    {
        return $this->lowStockCount > 0
            ? route('inventory.stock.index', ['filter' => ['low' => 1]])
            : route('inventory.batches.expiry', ['days' => $this->expiryDays]);
    }
}
