<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Enums\PaidFrom;
use App\Models\User;

/**
 * Expenses. The Manager records petty cash from the drawer only; the Super Admin pays
 * from the safe or a bank too, cancels expenses and manages the categories.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('finance.expenses.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('finance.expenses.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('finance.expenses.manage');
    }

    public function payFrom(User $user, PaidFrom $from): bool
    {
        return $user->can('finance.expenses.manage') && ($from === PaidFrom::CashDrawer || $user->can('finance.banks.manage'));
    }

    public function cancel(User $user): bool
    {
        return $user->can('finance.banks.manage');
    }

    public function manageCategories(User $user): bool
    {
        return $user->can('finance.banks.manage');
    }
}
