<?php

namespace App\Domain\Identity\Services;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\TerminalType;
use App\Domain\Identity\Models\Delegation;
use App\Models\User;

/**
 * Resolves who currently holds cashier authority (the cash drawer and settlement).
 *
 * The holder of the open drawer session on the main cashier terminal; if no drawer is
 * open, the holder of an active delegation that includes "pos.settle"; otherwise the
 * owner (first active Super Admin).
 */
class CashierAuthority
{
    public function holder(): ?User
    {
        $session = $this->openSession();

        if ($session?->holder !== null) {
            return $session->holder;
        }

        $delegation = $this->activeDelegation();

        if ($delegation?->toUser !== null) {
            return $delegation->toUser;
        }

        return User::query()
            ->active()
            ->role(Role::SuperAdmin->value)
            ->orderBy('id')
            ->first();
    }

    /**
     * The open drawer session on the main cashier terminal, if any.
     */
    public function openSession(): ?DrawerSession
    {
        return DrawerSession::query()
            ->open()
            ->whereHas('terminal', fn ($query) => $query->where('type', TerminalType::MainCashier))
            ->with('holder')
            ->latest('opened_at')
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

    /**
     * @return array{id: int, name: string}|null
     */
    public function holderSummary(): ?array
    {
        $holder = $this->holder();

        return $holder !== null ? ['id' => $holder->id, 'name' => $holder->name] : null;
    }
}
