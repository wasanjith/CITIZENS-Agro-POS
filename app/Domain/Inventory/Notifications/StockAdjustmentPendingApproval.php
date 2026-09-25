<?php

namespace App\Domain\Inventory\Notifications;

use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\System\Notifications\AppNotification;

class StockAdjustmentPendingApproval extends AppNotification
{
    public function __construct(
        public readonly int $adjustmentId,
        public readonly string $number,
        public readonly string $value,
        public readonly string $createdBy,
    ) {}

    public static function for(StockAdjustment $adjustment, string $createdBy): self
    {
        return new self($adjustment->id, $adjustment->number, $adjustment->total_value, $createdBy);
    }

    public function title(): string
    {
        return "Stock adjustment {$this->number} needs approval";
    }

    public function message(): string
    {
        return "{$this->createdBy} adjusted stock worth Rs. ".number_format((float) $this->value, 2).'.';
    }

    public function url(): string
    {
        return route('inventory.adjustments.show', $this->adjustmentId);
    }
}
