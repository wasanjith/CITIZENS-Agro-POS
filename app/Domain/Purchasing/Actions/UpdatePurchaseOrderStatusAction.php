<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The simple status steps of a purchase order: sent, cancelled, closed.
 * (Received/partial are set by PostGoodsReceiptAction.)
 */
class UpdatePurchaseOrderStatusAction
{
    /**
     * APPROVED → SENT once the PDF went to the supplier. Later steps keep their status.
     */
    public function markSent(PurchaseOrder $order): PurchaseOrder
    {
        return $this->change($order, function (PurchaseOrder $order): void {
            if ($order->status === PurchaseOrderStatus::Approved) {
                $order->status = PurchaseOrderStatus::Sent;
            }
            $order->sent_at ??= now();
        });
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        return $this->change($order, function (PurchaseOrder $order): void {
            if (! $order->status->isCancellable() || $order->goodsReceipts()->exists()) {
                throw ValidationException::withMessages(['status' => "Purchase order {$order->number} cannot be cancelled any more."]);
            }
            $order->status = PurchaseOrderStatus::Cancelled;
        });
    }

    /**
     * PARTIAL/RECEIVED → CLOSED: nothing more will be delivered.
     */
    public function close(PurchaseOrder $order): PurchaseOrder
    {
        return $this->change($order, function (PurchaseOrder $order): void {
            if (! in_array($order->status, [PurchaseOrderStatus::Partial, PurchaseOrderStatus::Received], true)) {
                throw ValidationException::withMessages(['status' => "Purchase order {$order->number} cannot be closed now."]);
            }
            $order->status = PurchaseOrderStatus::Closed;
        });
    }

    /**
     * @param  callable(PurchaseOrder): void  $change
     */
    private function change(PurchaseOrder $order, callable $change): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $change): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            $change($order);
            $order->save();

            return $order;
        });
    }
}
