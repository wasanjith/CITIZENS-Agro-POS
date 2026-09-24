<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Tax;
use App\Models\User;

class TaxPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.settings.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('admin.settings.manage');
    }

    public function update(User $user, Tax $tax): bool
    {
        return $user->can('admin.settings.manage');
    }

    public function delete(User $user, Tax $tax): bool
    {
        return $user->can('admin.settings.manage');
    }
}
