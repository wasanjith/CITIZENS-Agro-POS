<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\AccountType;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\JournalLine;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Trial balance, profit & loss, balance sheet and account ledgers, all read from the
 * journal (so they always agree with each other).
 */
class FinancialReports
{
    public function __construct(private readonly ChartOfAccounts $accounts) {}

    /**
     * Σ debit − Σ credit per account over a period (either end may be open).
     *
     * @return array<int, BigDecimal> account_id => net debit
     */
    public function netByAccount(?DateTimeInterface $from, ?DateTimeInterface $to): array
    {
        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($from !== null, fn ($query) => $query->whereDate('journal_entries.date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('journal_entries.date', '<=', $to))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS net')
            ->toBase()
            ->pluck('net', 'account_id');

        $net = [];

        foreach ($rows as $accountId => $value) {
            $net[(int) $accountId] = Money::of((string) $value);
        }

        return $net;
    }

    /**
     * @return array{rows: list<array{account: Account, debit: string, credit: string}>, debit: string, credit: string, balanced: bool}
     */
    public function trialBalance(DateTimeInterface $asOf): array
    {
        $net = $this->netByAccount(null, $asOf);
        $rows = [];
        $debits = Money::zero();
        $credits = Money::zero();

        foreach (Account::query()->whereIn('id', array_keys($net))->orderBy('code')->get() as $account) {
            $amount = $net[$account->id];

            if ($amount->isZero()) {
                continue;
            }

            $rows[] = [
                'account' => $account,
                'debit' => (string) ($amount->isPositive() ? $amount : Money::zero()),
                'credit' => (string) ($amount->isNegative() ? $amount->negated() : Money::zero()),
            ];
            $amount->isPositive() ? $debits = $debits->plus($amount) : $credits = $credits->plus($amount->negated());
        }

        return ['rows' => $rows, 'debit' => (string) $debits, 'credit' => (string) $credits, 'balanced' => $debits->isEqualTo($credits)];
    }

    /**
     * @return array{income: list<array{account: Account, amount: string}>, expenses: list<array{account: Account, amount: string}>, sales: string, returns: string, net_sales: string, cogs: string, gross_profit: string, other_income: string, total_expenses: string, net_profit: string}
     */
    public function profitAndLoss(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $balances = $this->balances($this->netByAccount($from, $to), [AccountType::Income, AccountType::Expense]);

        $salesId = $this->accounts->id(SystemAccount::SalesRevenue);
        $returnsId = $this->accounts->id(SystemAccount::SalesReturns);
        $cogsId = $this->accounts->id(SystemAccount::CostOfGoodsSold);

        $sales = Money::zero();
        $returns = Money::zero();
        $cogs = Money::zero();
        $income = [];
        $expenses = [];
        $otherIncome = Money::zero();
        $totalExpenses = Money::zero();

        foreach ($balances as $row) {
            $amount = Money::of($row['amount']);

            if ($row['account']->id === $salesId) {
                $sales = $amount;
            } elseif ($row['account']->id === $returnsId) {
                $returns = $amount->negated();
            } elseif ($row['account']->id === $cogsId) {
                $cogs = $amount;
            } elseif ($row['account']->type === AccountType::Income) {
                $income[] = $row;
                $otherIncome = $otherIncome->plus($amount);
            } else {
                $expenses[] = $row;
                $totalExpenses = $totalExpenses->plus($amount);
            }
        }

        $netSales = $sales->minus($returns);
        $gross = $netSales->minus($cogs);

        return [
            'income' => $income,
            'expenses' => $expenses,
            'sales' => (string) $sales,
            'returns' => (string) $returns,
            'net_sales' => (string) $netSales,
            'cogs' => (string) $cogs,
            'gross_profit' => (string) $gross,
            'other_income' => (string) $otherIncome,
            'total_expenses' => (string) $totalExpenses,
            'net_profit' => (string) $gross->plus($otherIncome)->minus($totalExpenses),
        ];
    }

    /**
     * Assets = liabilities + equity + profit to date.
     *
     * @return array{assets: list<array{account: Account, amount: string}>, liabilities: list<array{account: Account, amount: string}>, equity: list<array{account: Account, amount: string}>, total_assets: string, total_liabilities: string, total_equity: string, profit_to_date: string, balanced: bool}
     */
    public function balanceSheet(DateTimeInterface $asOf): array
    {
        $groups = [];
        $sums = [];

        foreach (AccountType::cases() as $type) {
            $groups[$type->value] = [];
            $sums[$type->value] = Money::zero();
        }

        foreach ($this->balances($this->netByAccount(null, $asOf), AccountType::cases()) as $row) {
            $groups[$row['account']->type->value][] = $row;
            $sums[$row['account']->type->value] = $sums[$row['account']->type->value]->plus($row['amount']);
        }

        $profit = $sums[AccountType::Income->value]->minus($sums[AccountType::Expense->value]);
        $assets = $sums[AccountType::Asset->value];
        $liabilities = $sums[AccountType::Liability->value];
        $equity = $sums[AccountType::Equity->value]->plus($profit);

        return [
            'assets' => $groups[AccountType::Asset->value],
            'liabilities' => $groups[AccountType::Liability->value],
            'equity' => $groups[AccountType::Equity->value],
            'total_assets' => (string) $assets,
            'total_liabilities' => (string) $liabilities,
            'total_equity' => (string) $equity,
            'profit_to_date' => (string) $profit,
            'balanced' => $assets->isEqualTo($liabilities->plus($equity)),
        ];
    }

    /**
     * Lines of one account with a running balance (on the account's normal side).
     *
     * @return array{opening: string, closing: string, lines: list<array{line: JournalLine, balance: string}>}
     */
    public function ledger(Account $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $opening = $account->balance($from->copy()->subDay());
        $sign = $account->type->isDebitNormal() ? 1 : -1;

        $lines = JournalLine::query()
            ->select('journal_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereDate('journal_entries.date', '>=', $from)
            ->whereDate('journal_entries.date', '<=', $to)
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.id')
            ->with('entry.source')
            ->get();

        $balance = $opening;
        $rows = [];

        foreach ($lines as $line) {
            $net = Money::of($line->debit)->minus($line->credit);
            $balance = $balance->plus($sign > 0 ? $net : $net->negated());
            $rows[] = ['line' => $line, 'balance' => (string) $balance];
        }

        return ['opening' => (string) $opening, 'closing' => (string) $balance, 'lines' => $rows];
    }

    /**
     * Balance of every account of the given types that is not zero, on its normal side.
     *
     * @param  array<int, BigDecimal>  $net  account_id => net debit
     * @param  list<AccountType>  $types
     * @return list<array{account: Account, amount: string}>
     */
    private function balances(array $net, array $types): array
    {
        $accounts = Account::query()
            ->whereIn('id', array_keys($net))
            ->whereIn('type', array_map(fn (AccountType $type) => $type->value, $types))
            ->orderBy('code')
            ->get();

        $rows = [];

        foreach ($accounts as $account) {
            $amount = $account->type->isDebitNormal() ? $net[$account->id] : $net[$account->id]->negated();

            if (! $amount->isZero()) {
                $rows[] = ['account' => $account, 'amount' => (string) $amount];
            }
        }

        return $rows;
    }
}
