<?php

namespace App\Domain\Sales\Policies;

use App\Domain\Sales\Models\SaleReturn;
use App\Models\User;

class SaleReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['pos.refund', 'pos.settle', 'reports.sales']);
    }

    public function view(User $user, SaleReturn $return): bool
    {
        return $this->viewAny($user) || $return->created_by === $user->id;
    }

    /**
     * Returns always need cashier authority (refund from the drawer or to the account).
     */
    public function create(User $user): bool
    {
        return $user->can('pos.refund');
    }
}
