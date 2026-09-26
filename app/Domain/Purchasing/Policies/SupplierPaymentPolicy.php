<?php

namespace App\Domain\Purchasing\Policies;

use App\Models\User;

/**
 * Supplier payments: listed for whoever manages suppliers, made by the Super Admin.
 */
class SupplierPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['purchasing.suppliers.manage', 'purchasing.suppliers.pay']);
    }

    public function view(User $user): bool
    {
        return $user->canAny(['purchasing.suppliers.manage', 'purchasing.suppliers.pay']);
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.suppliers.pay');
    }
}
