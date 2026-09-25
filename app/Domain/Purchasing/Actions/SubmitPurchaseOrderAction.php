<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Notifications\PurchaseOrderSubmitted;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * DRAFT → SUBMITTED, and tell every Manager/Owner (except the submitter) that it waits for approval.
 */
class SubmitPurchaseOrderAction
{
    public function handle(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        $order = DB::transaction(function () use ($order): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->status->isEditable()) {
                throw ValidationException::withMessages(['status' => "Purchase order {$order->number} has already been submitted."]);
            }

            if ($order->lines()->doesntExist()) {
                throw ValidationException::withMessages(['lines' => 'Add at least one product before submitting.']);
            }

            $order->status = PurchaseOrderStatus::Submitted;
            $order->submitted_at = now();
            $order->rejected_reason = null;
            $order->save();

            return $order;
        });

        $approvers = User::permission('purchasing.po.approve')->active()->whereKeyNot($actor->id)->get();
        Notification::send($approvers, PurchaseOrderSubmitted::for($order->load('supplier'), $actor->name));

        return $order;
    }
}
