<?php

namespace App\Domain\HR\Policies;

use App\Domain\HR\Enums\LeaveStatus;
use App\Domain\HR\Models\LeaveRequest;
use App\Models\User;

/**
 * Staff ask for leave for themselves; the Super Admin approves or rejects it and can
 * enter leave for anyone. The Manager sees the list.
 */
class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['hr.attendance.view', 'hr.employees.manage']);
    }

    public function requestOwn(User $user): bool
    {
        return $user->can('hr.attendance.self');
    }

    public function create(User $user): bool
    {
        return $user->can('hr.employees.manage');
    }

    public function decide(User $user): bool
    {
        return $user->can('hr.employees.manage');
    }

    /**
     * The person who asked may withdraw a request that is still waiting.
     */
    public function withdraw(User $user, LeaveRequest $request): bool
    {
        return $request->status === LeaveStatus::Pending && $request->requested_by === $user->id;
    }
}
