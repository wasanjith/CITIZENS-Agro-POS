<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('catalog.view');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('catalog.manage');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can('catalog.manage');
    }

    /**
     * Bulk import from Excel.
     */
    public function import(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function managePrices(User $user): bool
    {
        return $user->can('catalog.prices.manage');
    }

    /**
     * Cost prices and margins. Hidden from Sales Staff everywhere (pages, JSON, exports).
     */
    public function viewCost(User $user): bool
    {
        return $user->can('catalog.cost.view');
    }
}
