<?php

namespace App\Domain\HR\Policies;

use App\Models\User;

/**
 * Employees, shifts, holidays and leave types: Super Admin only (never delegated).
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('hr.employees.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('hr.employees.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('hr.employees.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('hr.employees.manage');
    }

    public function delete(User $user): bool
    {
        return $user->can('hr.employees.manage');
    }
}
