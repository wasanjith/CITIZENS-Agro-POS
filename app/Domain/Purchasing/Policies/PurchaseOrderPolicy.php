<?php

namespace App\Domain\Purchasing\Policies;

use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.po.create') || $user->can('purchasing.po.approve');
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.po.create');
    }

    /**
     * Drafts and rejected orders can be changed by their creator or an approver.
     */
    public function update(User $user, PurchaseOrder $order): bool
    {
        return $order->status->isEditable()
            && (($user->can('purchasing.po.create') && $order->created_by === $user->id) || $user->can('purchasing.po.approve'));
    }

    public function submit(User $user, PurchaseOrder $order): bool
    {
        return $this->update($user, $order);
    }

    /**
     * Approve or reject (fills in costs).
     */
    public function approve(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchasing.po.approve')
            && in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted, PurchaseOrderStatus::Rejected], true);
    }

    /**
     * Download the PDF / share with the supplier. The PDF carries costs.
     */
    public function send(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchasing.po.approve')
            && in_array($order->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent, PurchaseOrderStatus::Partial, PurchaseOrderStatus::Received, PurchaseOrderStatus::Closed], true);
    }

    public function cancel(User $user, PurchaseOrder $order): bool
    {
        if (! $order->status->isCancellable()) {
            return false;
        }

        return $user->can('purchasing.po.approve') || $this->update($user, $order);
    }

    /**
     * Close a partly received order (the rest will not come).
     */
    public function close(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchasing.po.approve') && in_array($order->status, [PurchaseOrderStatus::Partial, PurchaseOrderStatus::Received], true);
    }

    public function receive(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchasing.grn.create') && $order->status->isReceivable();
    }

    /**
     * Unit costs and totals. Sales Staff never see them.
     */
    public function viewCost(User $user): bool
    {
        return $user->can('catalog.cost.view');
    }
}
