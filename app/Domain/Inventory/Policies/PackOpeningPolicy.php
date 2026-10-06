<?php

namespace App\Domain\Inventory\Policies;

use App\Domain\Inventory\Models\PackOpening;
use App\Models\User;

/**
 * Opening sealed packs moves stock between two products, so it needs the same
 * permission as a stock adjustment.
 */
class PackOpeningPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.adjust');
    }

    public function view(User $user, PackOpening $opening): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.adjust');
    }
}
