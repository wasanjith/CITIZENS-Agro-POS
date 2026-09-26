<?php

namespace App\Domain\HR\Policies;

use App\Models\User;

/**
 * Everyone clocks in and out and sees their own attendance; the Manager sees everyone's;
 * only the Super Admin corrects it.
 */
class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('hr.attendance.view');
    }

    public function viewOwn(User $user): bool
    {
        return $user->can('hr.attendance.self');
    }

    public function update(User $user): bool
    {
        return $user->can('hr.attendance.edit');
    }
}
