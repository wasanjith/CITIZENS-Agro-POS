<?php

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Expense heads. A new head gets its own expense account (6xxx) in the chart of accounts.
 */
class ExpenseCategoryController extends Controller
{
    public function index(): View
    {
        $this->authorize('manageCategories', Expense::class);

        return view('finance.expense-categories.index', [
            'categories' => ExpenseCategory::query()->with('account')->withCount('expenses')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, ChartOfAccounts $accounts): RedirectResponse
    {
        $this->authorize('manageCategories', Expense::class);

        $validated = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')]]);

        DB::transaction(fn () => ExpenseCategory::create([
            'name' => $validated['name'],
            'account_id' => $accounts->createExpenseAccount($validated['name'])->id,
            'is_active' => true,
        ]));

        return back()->with('success', "Expense head {$validated['name']} added.");
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $this->authorize('manageCategories', Expense::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')->ignore($expenseCategory->id)],
            'is_active' => ['boolean'],
        ]);

        DB::transaction(function () use ($expenseCategory, $validated, $request): void {
            $expenseCategory->update(['name' => $validated['name'], 'is_active' => $request->boolean('is_active')]);
            $expenseCategory->account()->firstOrFail()->update(['name' => $validated['name']]);
        });

        return back()->with('success', "Expense head {$validated['name']} saved.");
    }
}
