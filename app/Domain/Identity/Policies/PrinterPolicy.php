<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\Printer;
use App\Models\User;

class PrinterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.terminals.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('admin.terminals.manage');
    }

    public function update(User $user, Printer $printer): bool
    {
        return $user->can('admin.terminals.manage');
    }
}
