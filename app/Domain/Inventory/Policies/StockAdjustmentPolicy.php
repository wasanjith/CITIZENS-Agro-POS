<?php

namespace App\Domain\Inventory\Policies;

use App\Domain\Inventory\Enums\AdjustmentStatus;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Models\User;

class StockAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.adjust') || $user->can('inventory.adjust.approve');
    }

    public function view(User $user, StockAdjustment $adjustment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.adjust');
    }

    /**
     * Approve or reject an adjustment above the approval limit.
     */
    public function approve(User $user, StockAdjustment $adjustment): bool
    {
        return $user->can('inventory.adjust.approve') && $adjustment->status === AdjustmentStatus::PendingApproval;
    }

    /**
     * Post any value without waiting for approval.
     */
    public function approveAny(User $user): bool
    {
        return $user->can('inventory.adjust.approve');
    }
}
