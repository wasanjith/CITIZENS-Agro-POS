<?php

namespace App\Domain\HR\Policies;

use App\Models\User;

/**
 * Payroll, payslips, salary components and advances: Super Admin only (never delegated).
 */
class PayrollPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('hr.payroll.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('hr.payroll.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('hr.payroll.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('hr.payroll.manage');
    }
}
