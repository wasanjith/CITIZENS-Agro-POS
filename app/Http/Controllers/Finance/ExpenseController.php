<?php

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Actions\CancelExpenseAction;
use App\Domain\Finance\Actions\RecordExpenseAction;
use App\Domain\Finance\Enums\PaidFrom;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Services\DrawerCash;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Sales\Support\Money;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shop expenses with a photo of the bill. The Manager records petty cash from the drawer.
 */
class ExpenseController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Expense::class);

        $query = $this->applyListQuery(
            Expense::query()->with(['category', 'createdBy', 'bankAccount']),
            $request,
            searchable: ['number', 'payee', 'note', 'reference'],
            sortable: ['date', 'number', 'amount'],
            filters: [
                'category' => function (Builder $query, string $category): void {
                    $query->where('expense_category_id', (int) $category);
                },
                'paid_from' => function (Builder $query, string $from): void {
                    $query->where('paid_from', $from);
                },
                'from' => function (Builder $query, string $date): void {
                    $query->whereDate('date', '>=', $date);
                },
                'to' => function (Builder $query, string $date): void {
                    $query->whereDate('date', '<=', $date);
                },
            ],
            defaultSort: 'date',
        );

        $total = (clone $query)->reorder()->whereNull('cancelled_at')->sum('amount');

        return view('finance.expenses.index', [
            'expenses' => $query->orderByDesc('id')->paginate(50)->withQueryString(),
            'total' => (string) Money::of((string) $total),
            'categories' => ExpenseCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            'sources' => PaidFrom::expenseOptions(),
        ]);
    }

    public function create(Request $request, DrawerCash $drawerCash): View
    {
        $this->authorize('create', Expense::class);

        $sources = collect(PaidFrom::expenseOptions())->filter(fn ($label, $value) => $request->user()->can('payFrom', [Expense::class, PaidFrom::from($value)]))->all();

        return view('finance.expenses.create', [
            'categories' => ExpenseCategory::options(),
            'sources' => $sources,
            'banks' => BankAccount::options(),
            'drawerOpen' => $drawerCash->openSession() !== null,
        ]);
    }

    public function store(Request $request, RecordExpenseAction $record): RedirectResponse
    {
        $this->authorize('create', Expense::class);

        $validated = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'expense_category_id' => ['required', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'paid_from' => ['required', Rule::in(array_keys(PaidFrom::expenseOptions()))],
            'bank_account_id' => ['nullable', 'required_if:paid_from,bank', 'integer'],
            'payee' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $this->authorize('payFrom', [Expense::class, PaidFrom::from($validated['paid_from'])]);

        // Petty cash leaves the drawer now, so it is always today's expense.
        if ($validated['paid_from'] === PaidFrom::CashDrawer->value) {
            $validated['date'] = today()->toDateString();
        }

        $expense = $record->handle([...$validated, 'amount' => (string) $validated['amount']], $request->user(), $request->file('receipt'));

        return redirect()->route('finance.expenses.show', $expense)->with('success', "{$expense->number}: Rs. ".Money::format($expense->amount).' recorded.');
    }

    public function show(Expense $expense, FinancePosting $posting): View
    {
        $this->authorize('view', $expense);

        return view('finance.expenses.show', [
            'expense' => $expense->load(['category.account', 'createdBy', 'cancelledBy', 'bankAccount']),
            'entries' => $posting->entriesFor($expense),
        ]);
    }

    public function receipt(Expense $expense): StreamedResponse
    {
        $this->authorize('view', $expense);

        abort_if($expense->receipt_path === null || ! Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->response($expense->receipt_path);
    }

    public function cancel(Request $request, Expense $expense, CancelExpenseAction $cancel): RedirectResponse
    {
        $this->authorize('cancel', Expense::class);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $cancel->handle($expense, $request->user(), $validated['reason']);

        return back()->with('success', "{$expense->number} cancelled.");
    }
}
