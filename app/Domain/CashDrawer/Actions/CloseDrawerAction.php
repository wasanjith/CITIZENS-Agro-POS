<?php

namespace App\Domain\CashDrawer\Actions;

use App\Domain\CashDrawer\Enums\DrawerCloseReason;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Count the drawer and close the session: counted cash vs expected → variance.
 *
 * End of day is blocked while any invoice is still waiting for settlement; a handover
 * is not (unsettled invoices move with the cashier authority).
 */
class CloseDrawerAction
{
    public function __construct(
        private readonly DrawerCalculator $calculator,
        private readonly FinancePosting $finance,
    ) {}

    /**
     * @param  array<string, mixed>  $denominations
     */
    public function handle(DrawerSession $session, User $closedBy, array $denominations, DrawerCloseReason $reason, ?string $note = null): DrawerSession
    {
        return DB::transaction(function () use ($session, $closedBy, $denominations, $reason, $note): DrawerSession {
            $session = DrawerSession::query()->lockForUpdate()->findOrFail($session->id);

            if (! $session->isOpen()) {
                throw ValidationException::withMessages(['drawer' => 'This drawer session is already closed.']);
            }

            if ($reason === DrawerCloseReason::EndOfDay) {
                $waiting = Sale::query()->where('status', SaleStatus::Invoiced)->orderBy('invoice_no')->pluck('invoice_no');

                if ($waiting->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'drawer' => 'Settle or void these invoices before closing the day: '.$waiting->implode(', ').'.',
                    ]);
                }
            }

            $expected = $this->calculator->expectedCash($session);
            $counted = $this->calculator->countTotal($denominations);

            $session->forceFill([
                'closed_at' => now(),
                'closed_by' => $closedBy->id,
                'expected_cash' => (string) $expected,
                'counted_cash' => (string) $counted,
                'variance' => (string) $counted->minus($expected),
                'denominations' => $denominations,
                'close_reason' => $reason,
                'close_note' => $note !== null ? mb_substr($note, 0, 255) : null,
                'is_open' => null,
            ])->save();

            $this->finance->drawerClosed($session, $closedBy->id);

            return $session;
        });
    }
}
