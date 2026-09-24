<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Delegation;
use App\Models\User;

/**
 * Resolves who currently holds cashier authority (the cash drawer and settlement).
 *
 * Until drawer sessions exist (Phase 3) this is the holder of an active delegation
 * that includes "pos.settle", otherwise the owner (first active Super Admin).
 */
class CashierAuthority
{
    public function holder(): ?User
    {
        $delegation = Delegation::query()
            ->active()
            ->whereJsonContains('permissions', 'pos.settle')
            ->latest('starts_at')
            ->with('toUser')
            ->first();

        if ($delegation?->toUser !== null) {
            return $delegation->toUser;
        }

        return User::query()
            ->active()
            ->role(Role::SuperAdmin->value)
            ->orderBy('id')
            ->first();
    }

    public function activeDelegation(): ?Delegation
    {
        return Delegation::query()
            ->active()
            ->whereJsonContains('permissions', 'pos.settle')
            ->latest('starts_at')
            ->with(['toUser', 'fromUser'])
            ->first();
    }
}
