<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Notifications\PurchaseOrderReviewed;
use App\Domain\Purchasing\Support\LineMath;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manager/Owner confirms the unit costs and approves (→ APPROVED), or rejects with a reason.
 */
class ApprovePurchaseOrderAction
{
    /**
     * @param  array<int|string, string|null>  $costs  po_line id => unit cost per purchase unit
     */
    public function approve(PurchaseOrder $order, User $actor, array $costs, string $discount = '0', string $tax = '0'): PurchaseOrder
    {
        $order = DB::transaction(function () use ($order, $actor, $costs, $discount, $tax): PurchaseOrder {
            $order = $this->lockReviewable($order);

            foreach ($order->lines()->get() as $line) {
                $cost = $costs[$line->id] ?? $line->unit_cost;

                if ($cost === null || $cost === '') {
                    throw ValidationException::withMessages(["costs.{$line->id}" => 'Enter the unit cost of every line.']);
                }

                $line->unit_cost = (string) LineMath::money($cost);
                $line->line_total = (string) LineMath::lineTotal($line->qty, $line->unit_cost);
                $line->save();
            }

            $order->discount = (string) LineMath::money($discount);
            $order->tax = (string) LineMath::money($tax);
            $order->status = PurchaseOrderStatus::Approved;
            $order->submitted_at ??= now();
            $order->approved_by = $actor->id;
            $order->approved_at = now();
            $order->rejected_reason = null;
            $order->recalculateTotals();

            if (BigDecimal::of($order->total)->isNegative()) {
                throw ValidationException::withMessages(['discount' => 'The discount is larger than the order.']);
            }

            // Remember which supplier sells which product (used by reorder suggestions).
            $order->supplier->products()->syncWithoutDetaching(
                $order->lines()->pluck('product_id')->unique()->values()->all(),
            );

            return $order;
        });

        $this->notifyCreator($order, $actor);

        return $order;
    }

    public function reject(PurchaseOrder $order, User $actor, string $reason): PurchaseOrder
    {
        $order = DB::transaction(function () use ($order, $actor, $reason): PurchaseOrder {
            $order = $this->lockReviewable($order);

            $order->status = PurchaseOrderStatus::Rejected;
            $order->approved_by = $actor->id;
            $order->approved_at = now();
            $order->rejected_reason = $reason;
            $order->save();

            return $order;
        });

        $this->notifyCreator($order, $actor);

        return $order;
    }

    private function lockReviewable(PurchaseOrder $order): PurchaseOrder
    {
        $order = PurchaseOrder::query()->with('supplier')->lockForUpdate()->findOrFail($order->id);

        if (! in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted, PurchaseOrderStatus::Rejected], true)) {
            throw ValidationException::withMessages(['status' => "Purchase order {$order->number} is already {$order->status->label()}."]);
        }

        if ($order->lines()->doesntExist()) {
            throw ValidationException::withMessages(['lines' => 'The order has no lines.']);
        }

        return $order;
    }

    private function notifyCreator(PurchaseOrder $order, User $actor): void
    {
        $creator = $order->creator()->first();

        if ($creator !== null && $creator->id !== $actor->id) {
            $creator->notify(PurchaseOrderReviewed::for($order, $actor->name));
        }
    }
}
