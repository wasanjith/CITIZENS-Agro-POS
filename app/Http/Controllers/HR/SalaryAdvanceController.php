<?php

namespace App\Http\Controllers\HR;

use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Services\DrawerCash;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\HR\Actions\CancelSalaryAdvanceAction;
use App\Domain\HR\Actions\GiveSalaryAdvanceAction;
use App\Domain\HR\Enums\SalaryAdvanceStatus;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\SalaryAdvance;
use App\Domain\HR\Services\HrCash;
use App\Domain\Sales\Support\Money;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Salary advances: money lent to staff and recovered from the next payslips.
 */
class SalaryAdvanceController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SalaryAdvance::class);

        $query = $this->applyListQuery(
            SalaryAdvance::query()->with(['employee', 'createdBy']),
            $request,
            searchable: ['number', 'note'],
            sortable: ['date', 'number', 'amount'],
            filters: [
                'status' => function (Builder $query, string $status): void {
                    $query->where('status', $status);
                },
                'employee' => function (Builder $query, string $employee): void {
                    $query->where('employee_id', (int) $employee);
                },
            ],
            defaultSort: 'date',
        );

        $outstanding = SalaryAdvance::query()->where('status', SalaryAdvanceStatus::Active)->get()->reduce(fn ($sum, SalaryAdvance $advance) => $sum->plus($advance->outstanding()), Money::zero());

        return view('hr.advances.index', [
            'advances' => $query->orderByDesc('id')->paginate(50)->withQueryString(),
            'outstanding' => (string) $outstanding,
            'employees' => Employee::options(false),
            'statuses' => collect(SalaryAdvanceStatus::cases())->mapWithKeys(fn (SalaryAdvanceStatus $status) => [$status->value => $status->label()])->all(),
        ]);
    }

    public function create(Request $request, DrawerCash $drawerCash): View
    {
        $this->authorize('create', SalaryAdvance::class);

        return view('hr.advances.create', [
            'employees' => Employee::options(),
            'sources' => HrCash::sources(),
            'banks' => BankAccount::options(),
            'drawerOpen' => $drawerCash->openSession() !== null,
            'employeeId' => $request->integer('employee') ?: null,
        ]);
    }

    public function store(Request $request, GiveSalaryAdvanceAction $give): RedirectResponse
    {
        $this->authorize('create', SalaryAdvance::class);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'installments' => ['required', 'integer', 'min:1', 'max:24'],
            'paid_from' => ['required', Rule::in(array_keys(HrCash::sources()))],
            'bank_account_id' => ['nullable', 'required_if:paid_from,bank', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['paid_from'] === 'cash_drawer') {
            $validated['date'] = today()->toDateString();
        }

        $advance = $give->handle([...$validated, 'amount' => (string) $validated['amount']], $request->user());

        return redirect()->route('hr.advances.show', $advance)->with('success', "{$advance->number}: Rs. ".Money::format($advance->amount)." given to {$advance->employee->full_name}.");
    }

    public function show(SalaryAdvance $advance, FinancePosting $posting): View
    {
        $this->authorize('view', $advance);

        return view('hr.advances.show', [
            'advance' => $advance->load(['employee', 'createdBy', 'cancelledBy', 'bankAccount', 'recoveries.payslip.payrollRun']),
            'entries' => $posting->entriesFor($advance),
        ]);
    }

    public function cancel(Request $request, SalaryAdvance $advance, CancelSalaryAdvanceAction $cancel): RedirectResponse
    {
        $this->authorize('update', $advance);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $cancel->handle($advance, $request->user(), $validated['reason']);

        return back()->with('success', "{$advance->number} cancelled.");
    }
}
