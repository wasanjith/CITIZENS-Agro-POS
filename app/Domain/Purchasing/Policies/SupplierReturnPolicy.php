<?php

namespace App\Domain\Purchasing\Policies;

use App\Domain\Purchasing\Models\SupplierReturn;
use App\Models\User;

class SupplierReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.grn.create');
    }

    public function view(User $user, SupplierReturn $return): bool
    {
        return $user->can('purchasing.grn.create');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.grn.create');
    }
}
