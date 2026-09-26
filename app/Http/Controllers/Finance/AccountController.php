<?php

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Enums\AccountType;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Finance\Services\FinancialReports;
use App\Http\Controllers\Concerns\ChoosesPeriod;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Chart of accounts with balances, and the ledger of each account.
 */
class AccountController extends Controller
{
    use ChoosesPeriod;

    public function index(ChartOfAccounts $chart, FinancialReports $reports): View
    {
        $this->authorize('viewAny', Account::class);

        $chart->ensureAll();
        $net = $reports->netByAccount(null, null);

        return view('finance.accounts.index', [
            'accounts' => Account::query()->orderBy('code')->get()->groupBy(fn (Account $account) => $account->type->value),
            'net' => $net,
            'types' => AccountType::cases(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Account::class);

        return view('finance.accounts.form', ['account' => new Account(['type' => AccountType::Expense, 'is_active' => true]), 'types' => AccountType::options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Account::class);

        $validated = $this->validated($request);
        $account = Account::create([...$validated, 'is_system' => false, 'is_active' => true]);

        return redirect()->route('finance.accounts.show', $account)->with('success', "Account {$account->displayName()} added.");
    }

    public function show(Request $request, Account $account, FinancialReports $reports): View
    {
        $this->authorize('view', $account);

        [$from, $to] = $this->period($request);

        return view('finance.accounts.show', ['account' => $account, 'from' => $from, 'to' => $to, ...$reports->ledger($account, $from, $to)]);
    }

    public function edit(Account $account): View
    {
        $this->authorize('update', $account);

        return view('finance.accounts.form', ['account' => $account, 'types' => AccountType::options()]);
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        if ($account->is_system) {
            $account->update($request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:255']]));
        } else {
            $account->update([...$this->validated($request, $account), 'is_active' => $request->boolean('is_active')]);
        }

        return redirect()->route('finance.accounts.show', $account)->with('success', 'Account saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Account $account = null): array
    {
        $validated = $request->validate([
            'code' => ['required', 'digits_between:4,6', Rule::unique('accounts', 'code')->ignore($account?->id)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $type = AccountType::from($validated['type']);

        if (! str_starts_with($validated['code'], $type->codePrefix()) && ! ($type === AccountType::Expense && str_starts_with($validated['code'], '6'))) {
            throw ValidationException::withMessages(['code' => "{$type->label()} account codes start with {$type->codePrefix()}".($type === AccountType::Expense ? ' or 6' : '').'.']);
        }

        return $validated;
    }
}
