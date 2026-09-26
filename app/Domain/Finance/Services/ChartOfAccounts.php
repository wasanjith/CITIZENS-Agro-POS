<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\AccountType;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\Account;

/**
 * Finds the system accounts, creating any that are missing (so posting never fails on
 * a fresh database), and hands out codes for new bank and expense accounts.
 */
class ChartOfAccounts
{
    /**
     * @var array<string, Account>
     */
    private array $cache = [];

    public function get(SystemAccount $system): Account
    {
        if (isset($this->cache[$system->value])) {
            return $this->cache[$system->value];
        }

        $account = Account::query()->where('system_key', $system->value)->first();

        if ($account === null) {
            // Upsert so two first postings at the same moment cannot create it twice.
            Account::query()->toBase()->upsert([[
                'code' => $system->code(),
                'name' => $system->label(),
                'type' => $system->type()->value,
                'system_key' => $system->value,
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['system_key'], ['system_key']);

            $account = Account::query()->where('system_key', $system->value)->firstOrFail();
        }

        return $this->cache[$system->value] = $account;
    }

    public function id(SystemAccount $system): int
    {
        return $this->get($system)->id;
    }

    /**
     * Create every system account that does not exist yet.
     */
    public function ensureAll(): void
    {
        foreach (SystemAccount::cases() as $system) {
            $this->get($system);
        }
    }

    /**
     * Next free code in a range: bank accounts 1501–1599, expense heads 6001–6999.
     */
    public function nextCode(int $from, int $to): string
    {
        $used = Account::query()
            ->whereRaw('LENGTH(code) = ?', [strlen((string) $from)])
            ->whereBetween('code', [(string) $from, (string) $to])
            ->pluck('code')
            ->map(fn ($code) => (int) $code)
            ->all();

        for ($code = $from; $code <= $to; $code++) {
            if (! in_array($code, $used, true)) {
                return (string) $code;
            }
        }

        throw new \RuntimeException("No free account code between {$from} and {$to}.");
    }

    public function createBankLedgerAccount(string $name): Account
    {
        return Account::create([
            'code' => $this->nextCode(1501, 1599),
            'name' => mb_substr($name, 0, 120),
            'type' => AccountType::Asset,
            'is_system' => true,
            'is_active' => true,
        ]);
    }

    public function createExpenseAccount(string $name): Account
    {
        return Account::create([
            'code' => $this->nextCode(6001, 6999),
            'name' => mb_substr($name, 0, 120),
            'type' => AccountType::Expense,
            'is_system' => false,
            'is_active' => true,
        ]);
    }
}
