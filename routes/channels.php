<?php

use App\Domain\Identity\Support\CurrentTerminal;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Live Billing: the three counters in real time, settlement list, approval requests.
Broadcast::channel('live-billing', function (User $user) {
    return $user->can('pos.live_view');
});

// Who is signed in on which terminal (online / offline dots). Live Billing viewers join
// too, from any device, so they can see the others.
Broadcast::channel('pos-terminals', function (User $user) {
    $terminal = app(CurrentTerminal::class)->get();

    if ($terminal === null && ! $user->can('pos.live_view')) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'terminal_id' => $terminal?->id,
        'counter_no' => $terminal?->counter_no,
        'terminal' => $terminal?->displayName(),
    ];
});

// Messages for one device: void → restore cart, approval decisions, test prints.
Broadcast::channel('terminal.{terminalId}', function (User $user, int $terminalId) {
    return app(CurrentTerminal::class)->get()?->id === $terminalId;
});

// Top bars on every screen show the current cashier-authority holder.
Broadcast::channel('cashier-authority', function (User $user) {
    return true;
});
