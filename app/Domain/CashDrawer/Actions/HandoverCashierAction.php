<?php

namespace App\Domain\CashDrawer\Actions;

use App\Domain\CashDrawer\Enums\DrawerCloseReason;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Identity\Actions\CreateDelegationAction;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Delegation;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Identity\Services\PinVerifier;
use App\Domain\Sales\Events\CashierAuthorityChanged;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner hands cashier authority to the Manager on the main terminal.
 *
 *  1. The owner counts the drawer → session A closed (HANDOVER) with its variance.
 *     Unsettled invoices stay INVOICED and move with the authority.
 *  2. The Manager re-authenticates with their PIN and confirms the counted amount
 *     (a different count is a dispute: both see the difference and count again).
 *  3. A delegation is created (scope, expiry, reason) and session B is opened for the
 *     Manager with opening float = the counted cash.
 *
 * The controller then signs the owner out of the terminal and the Manager in.
 */
class HandoverCashierAction
{
    public function __construct(
        private readonly CloseDrawerAction $closeDrawer,
        private readonly OpenDrawerAction $openDrawer,
        private readonly CreateDelegationAction $createDelegation,
        private readonly DrawerCalculator $calculator,
        private readonly PinVerifier $pins,
        private readonly CashierAuthority $authority,
    ) {}

    /**
     * @param  array<string, mixed>  $denominations  the owner's count
     * @param  list<string>  $permissions
     * @return array{closed: DrawerSession, opened: DrawerSession, delegation: Delegation}
     */
    public function handle(
        User $owner,
        Terminal $terminal,
        DrawerSession $session,
        array $denominations,
        User $manager,
        string $managerPin,
        ?string $managerCounted,
        CarbonInterface $expiresAt,
        array $permissions,
        ?string $reason = null,
    ): array {
        if ($session->holder_user_id !== $owner->id || ! $session->isOpen() || $session->terminal_id !== $terminal->id) {
            throw ValidationException::withMessages(['drawer' => 'You can only hand over the drawer you hold on this terminal.']);
        }

        if ($manager->is($owner) || ! $manager->hasRole(Role::Manager->value)) {
            throw ValidationException::withMessages(['manager_id' => 'Choose the Manager who takes over.']);
        }

        if (! in_array('pos.settle', $permissions, true)) {
            throw ValidationException::withMessages(['permissions' => 'The handover must include settling invoices (pos.settle).']);
        }

        $counted = $this->calculator->countTotal($denominations);

        if ($managerCounted === null || trim($managerCounted) === '') {
            throw ValidationException::withMessages(['manager_counted' => 'The Manager must confirm the counted amount.']);
        }

        $managerAmount = Money::of($managerCounted);

        if ($managerAmount->compareTo($counted) !== 0) {
            throw ValidationException::withMessages([
                'manager_counted' => sprintf(
                    'The counts differ by Rs. %s (owner Rs. %s, Manager Rs. %s). Count the drawer again together.',
                    Money::format($managerAmount->minus($counted)->abs()),
                    Money::format($counted),
                    Money::format($managerAmount),
                ),
            ]);
        }

        $this->pins->verify($manager, $managerPin, $terminal->id, 'manager_pin');

        $result = DB::transaction(function () use ($owner, $terminal, $session, $denominations, $manager, $expiresAt, $permissions, $reason): array {
            $closed = $this->closeDrawer->handle($session, $owner, $denominations, DrawerCloseReason::Handover, $reason);
            $opened = $this->openDrawer->handle($terminal, $manager, $denominations, $closed);

            $delegation = $this->createDelegation->handle(
                $owner,
                $manager,
                $expiresAt,
                $permissions,
                $reason,
                drawerSessionId: $opened->id,
            );

            activity()->performedOn($opened)->causedBy($owner)->event('handover')
                ->withProperties(['from_session' => $closed->id, 'to_user' => $manager->username, 'counted' => $closed->counted_cash, 'variance' => $closed->variance, 'expires_at' => $expiresAt->toDateTimeString()])
                ->log("Cashier authority handed over to {$manager->name}");

            return ['closed' => $closed, 'opened' => $opened, 'delegation' => $delegation];
        });

        LiveBroadcast::send(new CashierAuthorityChanged($this->authority->holderSummary(), "{$owner->name} handed cashier authority to {$manager->name}."));

        return $result;
    }
}
