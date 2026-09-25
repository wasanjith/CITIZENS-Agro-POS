<?php

namespace App\Domain\Purchasing\Notifications;

use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\System\Notifications\AppNotification;

/**
 * Tells the creator that their purchase order was approved or rejected.
 */
class PurchaseOrderReviewed extends AppNotification
{
    public function __construct(
        public readonly int $purchaseOrderId,
        public readonly string $number,
        public readonly bool $approved,
        public readonly string $reviewedBy,
        public readonly ?string $reason = null,
    ) {}

    public static function for(PurchaseOrder $order, string $reviewedBy): self
    {
        return new self($order->id, $order->number, $order->rejected_reason === null, $reviewedBy, $order->rejected_reason);
    }

    public function title(): string
    {
        return "Purchase order {$this->number} ".($this->approved ? 'approved' : 'rejected');
    }

    public function message(): string
    {
        return $this->approved
            ? "{$this->reviewedBy} approved your order."
            : "{$this->reviewedBy} rejected your order: {$this->reason}";
    }

    public function url(): string
    {
        return route('purchasing.purchase-orders.show', $this->purchaseOrderId);
    }
}
