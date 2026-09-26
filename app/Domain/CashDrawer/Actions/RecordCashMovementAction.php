<?php

namespace App\Domain\CashDrawer\Actions;

use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\CashDrawer\Models\CashMovement;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pay in, pay out or safe drop on an open drawer session (reason required).
 */
class RecordCashMovementAction
{
    public function __construct(
        private readonly DrawerCalculator $calculator,
        private readonly FinancePosting $finance,
    ) {}

    public function handle(DrawerSession $session, User $user, CashMovementType $type, string $amount, string $reason): CashMovement
    {
        return DB::transaction(function () use ($session, $user, $type, $amount, $reason): CashMovement {
            $session = DrawerSession::query()->lockForUpdate()->findOrFail($session->id);

            if (! $session->isOpen()) {
                throw ValidationException::withMessages(['drawer' => 'This drawer session is closed.']);
            }

            $value = Money::of($amount);

            if (! $value->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount above 0.']);
            }

            if ($type->sign() < 0 && $value->isGreaterThan($this->calculator->expectedCash($session))) {
                throw ValidationException::withMessages(['amount' => 'The drawer does not hold that much cash (expected Rs. '.Money::format($this->calculator->expectedCash($session)).').']);
            }

            $movement = CashMovement::create([
                'drawer_session_id' => $session->id,
                'type' => $type,
                'amount' => (string) $value,
                'reason' => mb_substr($reason, 0, 255),
                'user_id' => $user->id,
            ]);

            $this->finance->cashMovement($movement, $user->id);

            return $movement;
        });
    }
}
