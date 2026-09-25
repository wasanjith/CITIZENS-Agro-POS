<?php

namespace App\Domain\Sales\Policies;

use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Models\Sale;
use App\Models\User;

class SalePolicy
{
    /**
     * Sales list in the back office: the cashier, Live Billing viewers and sales reports.
     */
    public function viewAny(User $user): bool
    {
        return $user->canAny(['pos.settle', 'pos.live_view', 'reports.sales']);
    }

    /**
     * Counter staff see the invoices they printed and those printed on their counter.
     */
    public function view(User $user, Sale $sale): bool
    {
        if ($this->viewAny($user) || $sale->invoiced_by === $user->id) {
            return true;
        }

        $terminal = app(CurrentTerminal::class)->get();

        return $user->can('pos.sell') && $terminal !== null && $terminal->id === $sale->invoiced_terminal_id;
    }

    public function reprint(User $user, Sale $sale): bool
    {
        return $user->can('pos.reprint') && $this->view($user, $sale);
    }

    public function settle(User $user, Sale $sale): bool
    {
        return $user->can('pos.settle');
    }

    public function void(User $user, Sale $sale): bool
    {
        return $user->can('pos.void');
    }

    /**
     * Take goods back against this invoice (at the main cashier).
     */
    public function return(User $user, Sale $sale): bool
    {
        return $user->can('pos.refund') && $sale->canBeReturned();
    }
}
