<?php

namespace App\Domain\Finance\Actions;

use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\BankTransaction;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Services\BankBook;
use App\Domain\Finance\Services\DrawerCash;
use App\Domain\Finance\Services\JournalService;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move money between cash at home (account "safe"), the drawer, card / transfer
 * payments not yet banked, the owner's own money and the bank accounts: cash to bank
 * (deposit), bank to home (withdrawal), bank to bank (transfer), card money into the
 * bank, owner puts money in (capital) or takes it out (drawings).
 *
 * Places are 'safe', 'drawer' and 'clearing' (from only, to a bank), 'owner' or 'bank:{id}'.
 */
class MoveMoneyAction
{
    public const SAFE = 'safe';

    public const DRAWER = 'drawer';

    public const OWNER = 'owner';

    public const CLEARING = 'clearing';

    public function __construct(
        private readonly JournalService $journal,
        private readonly BankBook $bankBook,
        private readonly DrawerCash $drawerCash,
    ) {}

    /**
     * @param  array{from: string, to: string, amount: string, date: string, reference?: string|null, note?: string|null}  $data
     */
    public function handle(array $data, User $user): JournalEntry
    {
        $amount = Money::of($data['amount']);
        $date = Carbon::parse($data['date']);
        $reference = trim((string) ($data['reference'] ?? '')) ?: null;
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above 0.']);
        }

        if ($data['from'] === $data['to']) {
            throw ValidationException::withMessages(['to' => 'Choose a different place to move the money to.']);
        }

        if (in_array($data['to'], [self::DRAWER, self::CLEARING], true)) {
            throw ValidationException::withMessages(['to' => 'Money can only be moved out of that place. Put cash into the drawer with a pay in on the drawer page.']);
        }

        if ($data['from'] === self::DRAWER && ! str_starts_with($data['to'], 'bank:')) {
            throw ValidationException::withMessages(['to' => 'Cash from the drawer can only go to a bank here. Use a safe drop on the drawer page to send it home.']);
        }

        if ($data['from'] === self::CLEARING && ! str_starts_with($data['to'], 'bank:')) {
            throw ValidationException::withMessages(['to' => 'Card and transfer payments can only go to a bank account.']);
        }

        return DB::transaction(function () use ($data, $amount, $date, $reference, $note, $user): JournalEntry {
            $fromBank = $this->bank($data['from'], 'from');
            $toBank = $this->bank($data['to'], 'to');
            $label = $this->label($data['from'], $fromBank).' → '.$this->label($data['to'], $toBank);
            $description = $label.($note !== null ? ": {$note}" : '');

            $transactions = [];

            if ($fromBank !== null) {
                $type = $toBank !== null ? BankTransactionType::TransferOut : BankTransactionType::Withdrawal;
                $transactions[] = $this->bankBook->record($fromBank, $type, $amount, $date, $description, $reference, null, $user->id);
            }

            if ($toBank !== null) {
                $type = $fromBank !== null ? BankTransactionType::TransferIn : BankTransactionType::Deposit;
                $transactions[] = $this->bankBook->record($toBank, $type, $amount, $date, $description, $reference, null, $user->id);
            }

            if ($data['from'] === self::DRAWER) {
                /** @var BankTransaction $deposit */
                $deposit = $transactions[0];
                $this->drawerCash->takeOut($amount, CashMovementType::BankDeposit, "Bank deposit: {$toBank?->displayName()}", $deposit, $user->id, 'amount');
            }

            $entry = $this->journal->post($description, $date, [
                ['account' => $this->account($data['to'], $toBank, true), 'debit' => $amount],
                ['account' => $this->account($data['from'], $fromBank, false), 'credit' => $amount],
            ], $transactions[0] ?? null, 'money.moved', $user->id);

            /** @var JournalEntry $entry */
            return $entry;
        });
    }

    /**
     * Places for the form: cash at home, drawer (when open), card / transfer payments,
     * the owner's own money and every active bank.
     *
     * @return array<string, string>
     */
    public function places(bool $withDrawer): array
    {
        $places = [self::SAFE => 'Cash at home (day\'s takings)'];

        if ($withDrawer && $this->drawerCash->openSession() !== null) {
            $places[self::DRAWER] = 'Cash drawer (main cashier)';
        }

        $places[self::CLEARING] = 'Card & transfer payments (not yet in a bank)';
        $places[self::OWNER] = 'Owner\'s own money (capital in / drawings out)';

        foreach (BankAccount::options() as $id => $name) {
            $places["bank:{$id}"] = $name;
        }

        return $places;
    }

    private function bank(string $place, string $field): ?BankAccount
    {
        if (! str_starts_with($place, 'bank:')) {
            if (! in_array($place, [self::SAFE, self::DRAWER, self::OWNER, self::CLEARING], true)) {
                throw ValidationException::withMessages([$field => 'Choose where the money is.']);
            }

            return null;
        }

        $bank = BankAccount::query()->active()->find((int) substr($place, 5));

        if ($bank === null) {
            throw ValidationException::withMessages([$field => 'Choose an active bank account.']);
        }

        return $bank;
    }

    private function account(string $place, ?BankAccount $bank, bool $receiving): SystemAccount|int
    {
        return match (true) {
            $bank !== null => $bank->account_id,
            $place === self::SAFE => SystemAccount::Safe,
            $place === self::DRAWER => SystemAccount::CashDrawer,
            $place === self::CLEARING => SystemAccount::CardClearing,
            default => $receiving ? SystemAccount::OwnerDrawings : SystemAccount::OwnerCapital,
        };
    }

    private function label(string $place, ?BankAccount $bank): string
    {
        return match (true) {
            $bank !== null => $bank->displayName(),
            $place === self::SAFE => 'Cash at home',
            $place === self::DRAWER => 'Drawer',
            $place === self::CLEARING => 'Card & transfer payments',
            default => 'Owner',
        };
    }
}
