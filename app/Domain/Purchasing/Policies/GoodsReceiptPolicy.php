<?php

namespace App\Domain\Purchasing\Policies;

use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Models\User;

class GoodsReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.grn.create');
    }

    public function view(User $user, GoodsReceipt $receipt): bool
    {
        return $user->can('purchasing.grn.create');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.grn.create');
    }

    /**
     * Only drafts can be changed, posted or cancelled. Posted GRNs are corrected with a supplier return.
     */
    public function update(User $user, GoodsReceipt $receipt): bool
    {
        return $user->can('purchasing.grn.create') && $receipt->status === GoodsReceiptStatus::Draft;
    }

    public function post(User $user, GoodsReceipt $receipt): bool
    {
        return $this->update($user, $receipt);
    }

    public function cancel(User $user, GoodsReceipt $receipt): bool
    {
        return $this->update($user, $receipt);
    }
}
