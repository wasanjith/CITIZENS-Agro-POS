<?php

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Actions\ReconcileBankAccountAction;
use App\Domain\Finance\Actions\RecordBankChargeAction;
use App\Domain\Finance\Actions\SaveBankAccountAction;
use App\Domain\Finance\Enums\BankAccountType;
use App\Domain\Finance\Enums\ChequeDirection;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\BankTransaction;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Finance\Services\DrawerCash;
use App\Domain\Sales\Support\Money;
use App\Http\Controllers\Concerns\ChoosesPeriod;
use App\Http\Controllers\Controller;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Banking overview, bank accounts, the bank book, charges / interest and reconciliation.
 */
class BankAccountController extends Controller
{
    use ChoosesPeriod;

    public function index(ChartOfAccounts $accounts, DrawerCash $drawerCash): View
    {
        $this->authorize('viewAny', BankAccount::class);

        $banks = BankAccount::query()->orderByDesc('is_active')->orderBy('bank_name')->get();
        $balances = $banks->mapWithKeys(fn (BankAccount $bank) => [$bank->id => $bank->balance()]);

        return view('finance.bank-accounts.index', [
            'banks' => $banks,
            'balances' => $balances,
            'totalBank' => $balances->reduce(fn (BigDecimal $sum, BigDecimal $balance) => $sum->plus($balance), Money::zero()),
            'safe' => $accounts->get(SystemAccount::Safe)->balance(),
            'drawer' => $accounts->get(SystemAccount::CashDrawer)->balance(),
            'drawerOpen' => $drawerCash->openSession() !== null,
            'chequesInHand' => $accounts->get(SystemAccount::ChequesInHand)->balance(),
            'chequesIssued' => $accounts->get(SystemAccount::ChequesIssued)->balance(),
            'clearing' => $accounts->get(SystemAccount::CardClearing)->balance(),
            'receivedDue' => Cheque::query()->where('direction', ChequeDirection::Received)->open()->whereDate('cheque_date', '<=', today())->count(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', BankAccount::class);

        return view('finance.bank-accounts.form', [
            'bank' => new BankAccount(['type' => BankAccountType::Current, 'opening_balance' => '0.00', 'opening_date' => today(), 'is_active' => true, 'receives_card_payments' => false]),
            'types' => BankAccountType::options(),
        ]);
    }

    public function store(Request $request, SaveBankAccountAction $save): RedirectResponse
    {
        $this->authorize('create', BankAccount::class);

        $bank = $save->handle($this->validated($request), $request->user());

        return redirect()->route('finance.bank-accounts.show', $bank)->with('success', "Bank account {$bank->displayName()} added.");
    }

    public function show(Request $request, BankAccount $bankAccount): View
    {
        $this->authorize('view', $bankAccount);

        [$from, $to] = $this->period($request);
        $opening = $bankAccount->balance($from->copy()->subDay());
        $transactions = $bankAccount->transactions()
            ->with('createdBy')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $balance = $opening;
        $rows = $transactions->map(function (BankTransaction $transaction) use (&$balance) {
            $balance = $balance->plus($transaction->signedAmount());

            return ['transaction' => $transaction, 'balance' => (string) $balance];
        });

        return view('finance.bank-accounts.show', [
            'bank' => $bankAccount,
            'from' => $from,
            'to' => $to,
            'opening' => (string) $opening,
            'rows' => $rows,
            'closing' => (string) $balance,
            'current' => $bankAccount->balance(),
            'unreconciled' => $bankAccount->transactions()->whereNull('reconciled_at')->count(),
            'lastReconciliation' => $bankAccount->reconciliations()->latest('statement_date')->latest('id')->first(),
        ]);
    }

    public function edit(BankAccount $bankAccount): View
    {
        $this->authorize('update', $bankAccount);

        return view('finance.bank-accounts.form', ['bank' => $bankAccount, 'types' => BankAccountType::options()]);
    }

    public function update(Request $request, BankAccount $bankAccount, SaveBankAccountAction $save): RedirectResponse
    {
        $this->authorize('update', $bankAccount);

        $save->handle($this->validated($request), $request->user(), $bankAccount);

        return redirect()->route('finance.bank-accounts.show', $bankAccount)->with('success', 'Bank account updated.');
    }

    /**
     * POST: a bank charge or interest from the statement.
     */
    public function charge(Request $request, BankAccount $bankAccount, RecordBankChargeAction $record): RedirectResponse
    {
        $this->authorize('update', $bankAccount);

        $validated = $request->validate([
            'type' => ['required', 'in:charge,interest'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:200'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $transaction = $record->handle($bankAccount, [...$validated, 'amount' => (string) $validated['amount']], $request->user());

        return back()->with('success', "{$transaction->type->label()} of Rs. ".Money::format($transaction->amount).' recorded.');
    }

    public function reconcileForm(Request $request, BankAccount $bankAccount): View
    {
        $this->authorize('update', $bankAccount);

        $statementDate = $request->date('statement_date') ?? today();

        return view('finance.bank-accounts.reconcile', [
            'bank' => $bankAccount,
            'statementDate' => $statementDate,
            'cleared' => (string) Money::of($bankAccount->opening_balance)->plus($bankAccount->movementTotal(reconciledOnly: true)),
            'transactions' => $bankAccount->transactions()->whereNull('reconciled_at')->whereDate('date', '<=', $statementDate)->orderBy('date')->orderBy('id')->get(),
            'history' => $bankAccount->reconciliations()->with('createdBy')->latest('statement_date')->latest('id')->limit(10)->get(),
        ]);
    }

    public function reconcile(Request $request, BankAccount $bankAccount, ReconcileBankAccountAction $reconcile): RedirectResponse
    {
        $this->authorize('update', $bankAccount);

        $validated = $request->validate([
            'statement_date' => ['required', 'date', 'before_or_equal:today'],
            'statement_balance' => ['required', 'numeric', 'min:-9999999999', 'max:9999999999', 'decimal:0,2'],
            'transactions' => ['nullable', 'array'],
            'transactions.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $reconciliation = $reconcile->handle(
            $bankAccount,
            Carbon::parse($validated['statement_date']),
            (string) $validated['statement_balance'],
            array_map('intval', $validated['transactions'] ?? []),
            $request->user(),
            $validated['note'] ?? null,
        );

        $message = Money::of($reconciliation->difference)->isZero()
            ? 'Reconciled: the bank book agrees with the statement.'
            : 'Saved. The statement differs from the ticked transactions by Rs. '.Money::format($reconciliation->difference).'.';

        return redirect()->route('finance.bank-accounts.show', $bankAccount)->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'bank_name' => ['required', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'account_no' => ['required', 'string', 'max:40'],
            'account_name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(BankAccountType::class)],
            'opening_balance' => ['nullable', 'numeric', 'min:-9999999999', 'max:9999999999', 'decimal:0,2'],
            'opening_date' => ['required', 'date'],
            'receives_card_payments' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        return [
            ...$validated,
            'opening_balance' => (string) ($validated['opening_balance'] ?? '0'),
            'receives_card_payments' => $request->boolean('receives_card_payments'),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
