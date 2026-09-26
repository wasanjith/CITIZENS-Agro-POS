<?php

namespace App\Domain\Finance\Services;

use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\CashDrawer\Models\CashMovement;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Identity\Enums\TerminalType;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Cash taken out of (or put back into) the open drawer at the main cashier for a
 * finance document: petty-cash expense, supplier payment, bank deposit. The movement
 * points at the document, which posts the journal entry itself.
 */
class DrawerCash
{
    public function __construct(private readonly DrawerCalculator $calculator) {}

    public function openSession(): ?DrawerSession
    {
        return DrawerSession::query()
            ->open()
            ->whereHas('terminal', fn ($query) => $query->where('type', TerminalType::MainCashier))
            ->latest('id')
            ->first();
    }

    /**
     * @param  string  $field  form field that gets the error message
     */
    public function takeOut(BigDecimal $amount, CashMovementType $type, string $reason, Model $reference, int $userId, string $field = 'paid_from'): CashMovement
    {
        $session = $this->lockedOpenSession($field);
        $available = $this->calculator->expectedCash($session);

        if ($amount->isGreaterThan($available)) {
            throw ValidationException::withMessages([$field => 'The drawer does not hold that much cash (expected Rs. '.Money::format($available).').']);
        }

        return $this->move($session, $type, $amount, $reason, $reference, $userId);
    }

    /**
     * Cash back into the drawer (a cancelled petty-cash expense).
     */
    public function putBack(BigDecimal $amount, string $reason, Model $reference, int $userId, int $sessionId): CashMovement
    {
        $session = DrawerSession::query()->lockForUpdate()->find($sessionId);

        if ($session === null || ! $session->isOpen()) {
            throw ValidationException::withMessages(['reason' => 'The drawer session this cash came from is closed. Put the cash back with a pay in on the drawer page instead.']);
        }

        return $this->move($session, CashMovementType::PayIn, $amount, $reason, $reference, $userId);
    }

    private function lockedOpenSession(string $field): DrawerSession
    {
        $session = $this->openSession();
        $session = $session !== null ? DrawerSession::query()->lockForUpdate()->find($session->id) : null;

        if ($session === null || ! $session->isOpen()) {
            throw ValidationException::withMessages([$field => 'No drawer is open at the main cashier.']);
        }

        return $session;
    }

    private function move(DrawerSession $session, CashMovementType $type, BigDecimal $amount, string $reason, Model $reference, int $userId): CashMovement
    {
        return CashMovement::create([
            'drawer_session_id' => $session->id,
            'type' => $type,
            'amount' => (string) Money::of($amount),
            'reason' => mb_substr($reason, 0, 255),
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'user_id' => $userId,
        ]);
    }
}
