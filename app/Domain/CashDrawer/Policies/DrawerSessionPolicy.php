<?php

namespace App\Domain\CashDrawer\Policies;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Models\User;

class DrawerSessionPolicy
{
    /**
     * Drawer and handover history.
     */
    public function viewAny(User $user): bool
    {
        return $user->canAny(['drawer.handover', 'drawer.manage', 'reports.sales']);
    }

    public function view(User $user, DrawerSession $session): bool
    {
        return $this->viewAny($user) || $session->holder_user_id === $user->id;
    }
}
