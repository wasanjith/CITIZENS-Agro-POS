<?php

namespace App\Domain\CashDrawer\Actions;

use App\Domain\CashDrawer\Enums\DrawerCloseReason;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Identity\Actions\RevokeDelegationAction;
use App\Domain\Identity\Models\Delegation;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Identity\Services\PinVerifier;
use App\Domain\Sales\Events\CashierAuthorityChanged;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Take back: the delegate counts the drawer → their session is closed (HANDOVER) with
 * its variance → the delegation is revoked. If the owner is present they enter their
 * PIN and a new session is opened for them with the counted cash; otherwise the owner
 * opens the drawer later and the new session continues the day's chain.
 *
 * Also used when a delegation was revoked remotely or expired: the delegate can still
 * count and close the drawer they hold. The owner may close it themselves when the
 * delegate is not there.
 */
class ReturnCashierAuthorityAction
{
    public function __construct(
        private readonly CloseDrawerAction $closeDrawer,
        private readonly OpenDrawerAction $openDrawer,
        private readonly RevokeDelegationAction $revoke,
        private readonly PinVerifier $pins,
        private readonly CashierAuthority $authority,
    ) {}

    /**
     * @param  array<string, mixed>  $denominations
     * @return array{closed: DrawerSession, opened: DrawerSession|null}
     */
    public function handle(
        User $actingUser,
        Terminal $terminal,
        DrawerSession $session,
        array $denominations,
        ?User $newHolder = null,
        ?string $newHolderPin = null,
        ?string $note = null,
    ): array {
        if (! $session->isOpen() || $session->terminal_id !== $terminal->id) {
            throw ValidationException::withMessages(['drawer' => 'This drawer session is not open on this terminal.']);
        }

        $isHolder = $session->holder_user_id === $actingUser->id;

        if (! $isHolder && ! $actingUser->can('drawer.handover')) {
            throw ValidationException::withMessages(['drawer' => 'Only the drawer holder or the owner can count and close this drawer.']);
        }

        if ($newHolder !== null) {
            if ($newHolder->id === $session->holder_user_id) {
                throw ValidationException::withMessages(['new_holder_id' => 'Choose who takes the drawer next.']);
            }

            if (! $newHolder->can('drawer.handover')) {
                throw ValidationException::withMessages(['new_holder_id' => 'Only the owner can take cashier authority back.']);
            }

            if (! $newHolder->is($actingUser)) {
                $this->pins->verify($newHolder, (string) $newHolderPin, $terminal->id, 'new_holder_pin');
            }
        }

        $holder = $session->holder;

        $result = DB::transaction(function () use ($actingUser, $terminal, $session, $denominations, $newHolder, $note, $holder): array {
            $closed = $this->closeDrawer->handle($session, $actingUser, $denominations, DrawerCloseReason::Handover, $note);

            Delegation::query()
                ->where('to_user_id', $holder->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->get()
                ->each(fn (Delegation $delegation) => $this->revoke->handle($delegation, $actingUser));

            $opened = $newHolder !== null ? $this->openDrawer->handle($terminal, $newHolder, $denominations, $closed) : null;

            activity()->performedOn($closed)->causedBy($actingUser)->event('handback')
                ->withProperties(['from_user' => $holder->username, 'to_user' => $newHolder?->username, 'counted' => $closed->counted_cash, 'variance' => $closed->variance])
                ->log("Cashier authority returned by {$holder->name}");

            return ['closed' => $closed, 'opened' => $opened];
        });

        LiveBroadcast::send(new CashierAuthorityChanged(
            $this->authority->holderSummary(),
            $newHolder !== null ? "{$newHolder->name} took cashier authority back from {$holder->name}." : "{$holder->name} counted and closed the drawer.",
        ));

        return $result;
    }
}
