<?php

namespace App\Domain\Purchasing\Policies;

use App\Domain\Purchasing\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.suppliers.manage');
    }

    /**
     * Supplier page with balance and ledger.
     */
    public function view(User $user, Supplier $supplier): bool
    {
        return $user->can('purchasing.suppliers.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.suppliers.manage');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can('purchasing.suppliers.manage');
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->can('purchasing.suppliers.manage');
    }

    /**
     * Pick a supplier by name (purchase order form).
     */
    public function lookup(User $user): bool
    {
        return $user->can('purchasing.po.create') || $user->can('purchasing.grn.create');
    }
}
