<?php

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Actions\MoveMoneyAction;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Sales\Support\Money;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cash to bank, bank to safe, bank to bank, owner capital and drawings.
 */
class MoneyMoveController extends Controller
{
    public function create(Request $request, MoveMoneyAction $move): View
    {
        $this->authorize('update', BankAccount::class);

        return view('finance.money.create', [
            'places' => $move->places(withDrawer: true),
            'from' => $request->query('from', MoveMoneyAction::SAFE),
            'to' => $request->query('to'),
        ]);
    }

    public function store(Request $request, MoveMoneyAction $move): RedirectResponse
    {
        $this->authorize('update', BankAccount::class);

        $validated = $request->validate([
            'from' => ['required', 'string', 'max:20'],
            'to' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $entry = $move->handle([...$validated, 'amount' => (string) $validated['amount']], $request->user());

        return redirect()->route('finance.bank-accounts.index')->with('success', 'Rs. '.Money::format($validated['amount'])." moved ({$entry->description}).");
    }
}
