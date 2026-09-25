<?php

namespace App\Domain\Inventory\Policies;

use App\Domain\Inventory\Enums\StocktakeStatus;
use App\Domain\Inventory\Models\Stocktake;
use App\Models\User;

class StocktakePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.stocktake');
    }

    public function view(User $user, Stocktake $stocktake): bool
    {
        return $user->can('inventory.stocktake');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.stocktake');
    }

    public function count(User $user, Stocktake $stocktake): bool
    {
        return $user->can('inventory.stocktake') && $stocktake->status === StocktakeStatus::Counting;
    }

    /**
     * Finish counting (→ review) or reopen counting from review.
     */
    public function review(User $user, Stocktake $stocktake): bool
    {
        return $user->can('inventory.stocktake') && in_array($stocktake->status, [StocktakeStatus::Counting, StocktakeStatus::Review], true);
    }

    /**
     * Post the variances. Above the adjustment approval limit PostStocktakeAction also
     * requires inventory.adjust.approve.
     */
    public function post(User $user, Stocktake $stocktake): bool
    {
        return $user->can('inventory.stocktake') && $stocktake->status === StocktakeStatus::Review;
    }

    public function cancel(User $user, Stocktake $stocktake): bool
    {
        return $user->can('inventory.stocktake') && ! $stocktake->status->isFinished();
    }
}
