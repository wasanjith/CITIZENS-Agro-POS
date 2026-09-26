<?php

namespace App\Domain\Finance\Policies;

use App\Models\User;

/**
 * The cheque register: Super Admin only.
 */
class ChequePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('finance.cheques.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('finance.cheques.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('finance.cheques.manage');
    }
}
