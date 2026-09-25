<?php

namespace App\Domain\CashDrawer\Notifications;

use App\Domain\Identity\Models\Delegation;
use App\Domain\System\Notifications\AppNotification;

class DelegationExpired extends AppNotification
{
    public function __construct(private readonly Delegation $delegation) {}

    public function title(): string
    {
        return "Cashier authority of {$this->delegation->toUser->name} expired";
    }

    public function message(): string
    {
        return 'The handover ended at '.$this->delegation->expires_at->format('H:i').'. The drawer must be counted and closed before you take it back.';
    }

    public function url(): string
    {
        return route('pos.drawer.show');
    }
}
