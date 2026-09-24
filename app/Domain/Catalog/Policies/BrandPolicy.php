<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Brand;
use App\Models\User;

class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function update(User $user, Brand $brand): bool
    {
        return $user->can('catalog.manage');
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $user->can('catalog.manage');
    }
}
