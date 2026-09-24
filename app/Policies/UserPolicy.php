<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.users.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('admin.users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('admin.users.manage');
    }
}
