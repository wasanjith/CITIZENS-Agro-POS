<?php

namespace App\Domain\CashDrawer\Actions;

use App\Domain\CashDrawer\Enums\DrawerCloseReason;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Identity\Models\Terminal;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Start a drawer session on the main cashier terminal with a counted opening float.
 *
 * If the last session on this terminal was closed for a handover and nobody has taken
 * over yet (e.g. after a remote revoke), the new session continues that day's chain.
 */
class OpenDrawerAction
{
    public function __construct(private readonly DrawerCalculator $calculator) {}

    /**
     * @param  array<string, mixed>  $denominations
     */
    public function handle(Terminal $terminal, User $holder, array $denominations, ?DrawerSession $previous = null): DrawerSession
    {
        if (! $terminal->isMainCashier()) {
            throw ValidationException::withMessages(['terminal' => 'The cash drawer is only at the main cashier.']);
        }

        $float = $this->calculator->countTotal($denominations);

        try {
            return DB::transaction(function () use ($terminal, $holder, $denominations, $float, $previous): DrawerSession {
                if (DrawerSession::query()->where('terminal_id', $terminal->id)->open()->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['drawer' => 'A drawer session is already open on this terminal.']);
                }

                $previous ??= $this->waitingHandover($terminal);

                return DrawerSession::create([
                    'terminal_id' => $terminal->id,
                    'holder_user_id' => $holder->id,
                    'opened_at' => now(),
                    'opening_float' => (string) $float,
                    'opening_denominations' => $denominations,
                    'previous_session_id' => $previous?->id,
                    'is_open' => true,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['drawer' => 'A drawer session is already open on this terminal.']);
        }
    }

    /**
     * The last session on the terminal, if it was handed over and has no successor yet.
     */
    public function waitingHandover(Terminal $terminal): ?DrawerSession
    {
        $last = DrawerSession::query()
            ->where('terminal_id', $terminal->id)
            ->whereNotNull('closed_at')
            ->latest('closed_at')
            ->latest('id')
            ->first();

        if ($last === null || $last->close_reason !== DrawerCloseReason::Handover || $last->next()->exists()) {
            return null;
        }

        return $last;
    }
}
