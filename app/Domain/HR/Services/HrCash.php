<?php

namespace App\Domain\HR\Services;

use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Finance\Enums\PaidFrom;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Services\BankBook;
use App\Domain\Finance\Services\DrawerCash;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Money paid to staff (salaries, advances) and to the EPF/ETF funds: out of the drawer
 * (a pay out), the cash at home, or a bank account (a withdrawal in the bank book).
 * Returns the ledger account to credit; the caller posts the journal entry.
 */
class HrCash
{
    public function __construct(
        private readonly DrawerCash $drawerCash,
        private readonly BankBook $bankBook,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function sources(bool $withDrawer = true): array
    {
        return collect([PaidFrom::Safe, PaidFrom::Bank, ...($withDrawer ? [PaidFrom::CashDrawer] : [])])
            ->mapWithKeys(fn (PaidFrom $from) => [$from->value => $from->label()])
            ->all();
    }

    /**
     * @return array{0: PaidFrom, 1: BankAccount|null}
     */
    public function resolveSource(?string $value, int|string|null $bankId, bool $withDrawer = true, string $field = 'paid_from'): array
    {
        $from = PaidFrom::tryFrom((string) $value);

        if ($from === null || ! array_key_exists($from->value, self::sources($withDrawer))) {
            throw ValidationException::withMessages([$field => 'Choose where the money comes from.']);
        }

        $bank = $from === PaidFrom::Bank ? BankAccount::query()->active()->find((int) $bankId) : null;

        if ($from === PaidFrom::Bank && $bank === null) {
            throw ValidationException::withMessages(['bank_account_id' => 'Choose the bank account.']);
        }

        return [$from, $bank];
    }

    /**
     * @return array{account: SystemAccount|int, drawer_session_id: int|null}
     */
    public function payOut(PaidFrom $from, ?BankAccount $bank, BigDecimal $amount, Carbon $date, string $description, ?string $reference, Model $document, int $userId): array
    {
        return match ($from) {
            PaidFrom::CashDrawer => [
                'account' => SystemAccount::CashDrawer,
                'drawer_session_id' => $this->drawerCash->takeOut($amount, CashMovementType::PayOut, $description, $document, $userId)->drawer_session_id,
            ],
            PaidFrom::Bank => [
                'account' => $this->withdraw($bank, $amount, $date, $description, $reference, $document, $userId),
                'drawer_session_id' => null,
            ],
            default => ['account' => SystemAccount::Safe, 'drawer_session_id' => null],
        };
    }

    /**
     * Put money back where it was paid from (a cancelled advance). Drawer cash goes back
     * only while that drawer session is still open; otherwise to the cash at home.
     */
    public function putBack(PaidFrom $from, ?BankAccount $bank, ?int $drawerSessionId, BigDecimal $amount, string $description, Model $document, int $userId): SystemAccount|int
    {
        if ($from === PaidFrom::CashDrawer && $drawerSessionId !== null && $this->drawerCash->openSession()?->id === $drawerSessionId) {
            $this->drawerCash->putBack($amount, $description, $document, $userId, $drawerSessionId);

            return SystemAccount::CashDrawer;
        }

        if ($from === PaidFrom::Bank && $bank !== null) {
            $this->bankBook->record($bank, BankTransactionType::Deposit, $amount, today(), $description, null, $document, $userId);

            return $bank->account_id;
        }

        return SystemAccount::Safe;
    }

    private function withdraw(?BankAccount $bank, BigDecimal $amount, Carbon $date, string $description, ?string $reference, Model $document, int $userId): int
    {
        if ($bank === null) {
            throw ValidationException::withMessages(['bank_account_id' => 'Choose the bank account.']);
        }

        $this->bankBook->record($bank, BankTransactionType::Withdrawal, $amount, $date, $description, $reference, $document, $userId);

        return $bank->account_id;
    }
}
