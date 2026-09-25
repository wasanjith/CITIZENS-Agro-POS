<?php

namespace App\Domain\Purchasing\Notifications;

use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\System\Notifications\AppNotification;

/**
 * Sent to Managers and the Owner when a purchase order waits for approval.
 */
class PurchaseOrderSubmitted extends AppNotification
{
    public function __construct(
        public readonly int $purchaseOrderId,
        public readonly string $number,
        public readonly string $supplierName,
        public readonly string $submittedBy,
    ) {}

    public static function for(PurchaseOrder $order, string $submittedBy): self
    {
        return new self($order->id, $order->number, $order->supplier->name, $submittedBy);
    }

    public function title(): string
    {
        return "Purchase order {$this->number} needs approval";
    }

    public function message(): string
    {
        return "{$this->submittedBy} submitted an order for {$this->supplierName}.";
    }

    public function url(): string
    {
        return route('purchasing.purchase-orders.show', $this->purchaseOrderId);
    }
}
