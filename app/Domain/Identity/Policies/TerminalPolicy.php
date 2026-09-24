<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\Terminal;
use App\Models\User;

class TerminalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.terminals.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('admin.terminals.manage');
    }

    public function update(User $user, Terminal $terminal): bool
    {
        return $user->can('admin.terminals.manage');
    }

    /**
     * Bind (or unbind) the current browser to this terminal.
     */
    public function register(User $user, Terminal $terminal): bool
    {
        return $user->can('admin.terminals.manage') && $terminal->is_active;
    }
}
