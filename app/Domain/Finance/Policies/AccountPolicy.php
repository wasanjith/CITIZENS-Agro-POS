<?php

namespace App\Domain\Finance\Policies;

use App\Models\User;

/**
 * Chart of accounts, journal and financial reports: Super Admin only (never delegated).
 */
class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('finance.journal.view');
    }

    public function view(User $user): bool
    {
        return $user->can('finance.journal.view');
    }

    public function create(User $user): bool
    {
        return $user->can('finance.journal.view');
    }

    public function update(User $user): bool
    {
        return $user->can('finance.journal.view');
    }
}
