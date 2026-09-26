<?php

namespace App\Domain\Finance\Policies;

use App\Models\User;

/**
 * Bank accounts, the bank book, moving money and reconciliation: Super Admin only.
 */
class BankAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('finance.banks.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('finance.banks.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('finance.banks.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('finance.banks.manage');
    }
}
