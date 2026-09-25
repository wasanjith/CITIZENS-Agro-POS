<?php

namespace App\Domain\CashDrawer\Jobs;

use App\Domain\CashDrawer\Notifications\DelegationExpired;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Delegation;
use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Identity\Services\DelegationService;
use App\Domain\Sales\Events\CashierAuthorityChanged;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Every minute: announce delegations whose expiry time has passed.
 *
 * Permission checks already stop honouring a delegation the moment it expires, so the
 * delegate's next settlement is blocked with "count the drawer". This job tells every
 * screen (top bars, Live Billing) and notifies the owner.
 */
class ProcessExpiredDelegationsJob implements ShouldQueue
{
    use Queueable;

    public function handle(CashierAuthority $authority, DelegationService $delegations): void
    {
        $expired = Delegation::query()
            ->with('toUser')
            ->whereNull('revoked_at')
            ->whereNull('expiry_processed_at')
            ->where('expires_at', '<=', now())
            ->get();

        if ($expired->isEmpty()) {
            return;
        }

        $owners = User::query()->active()->role(Role::SuperAdmin->value)->get();

        foreach ($expired as $delegation) {
            $delegation->forceFill(['expiry_processed_at' => now()])->save();

            Notification::send($owners, new DelegationExpired($delegation));
        }

        $delegations->flush();

        LiveBroadcast::send(new CashierAuthorityChanged($authority->holderSummary(), 'A cashier handover expired.'));
    }
}
