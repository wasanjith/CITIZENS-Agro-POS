<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->can('catalog.manage');
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $user->can('catalog.manage');
    }
}
