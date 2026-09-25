<?php

namespace App\Domain\Sales\Policies;

use App\Domain\Sales\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['pos.sell', 'customers.view']);
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('pos.sell');
    }

    /**
     * Whoever wrote it, or the Owner / Manager.
     */
    public function cancel(User $user, Quotation $quotation): bool
    {
        return $quotation->created_by === $user->id || $user->can('customers.manage');
    }
}
