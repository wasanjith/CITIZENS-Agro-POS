<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Actions\SaveEmployeeAction;
use App\Domain\HR\Enums\EmploymentType;
use App\Domain\HR\Enums\SalaryAdvanceStatus;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\SalaryComponent;
use App\Domain\HR\Models\Shift;
use App\Domain\HR\Services\LeaveService;
use App\Domain\HR\Services\PayrollCalculator;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\HR\SaveEmployeeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Employees: personal details, salary, EPF, bank, salary components and the login
 * that clocks them in.
 */
class EmployeeController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Employee::class);

        $employees = $this->applyListQuery(
            Employee::query()->with(['user', 'shift']),
            $request,
            searchable: ['code', 'full_name', 'name_si', 'nic', 'phone', 'designation'],
            sortable: ['code', 'full_name', 'join_date', 'basic_salary'],
            filters: [
                'status' => function (Builder $query, string $status): void {
                    $query->where('is_active', $status === 'active');
                },
            ],
            defaultSort: 'code',
            defaultDirection: 'asc',
        )->paginate(50)->withQueryString();

        return view('hr.employees.index', ['employees' => $employees]);
    }

    public function create(): View
    {
        $this->authorize('create', Employee::class);

        return view('hr.employees.form', $this->formData(new Employee([
            'join_date' => today(),
            'employment_type' => EmploymentType::Permanent,
            'is_epf_member' => true,
            'is_active' => true,
            'basic_salary' => '0',
        ])));
    }

    public function store(SaveEmployeeRequest $request, SaveEmployeeAction $save): RedirectResponse
    {
        $this->authorize('create', Employee::class);

        $employee = $save->handle($request->validated());

        return redirect()->route('hr.employees.show', $employee)->with('success', "{$employee->code} {$employee->full_name} added.");
    }

    public function show(Employee $employee, LeaveService $leave, PayrollCalculator $calculator): View
    {
        $this->authorize('view', $employee);

        $monthStart = today()->startOfMonth();

        return view('hr.employees.show', [
            'employee' => $employee->load(['user', 'shift', 'salaryComponents']),
            'month' => $calculator->days($employee, $monthStart, today()),
            'balances' => $leave->balances($employee, today()->year),
            'advances' => $employee->advances()->latest('date')->limit(10)->get(),
            'owedOnAdvances' => $employee->advances()->where('status', SalaryAdvanceStatus::Active)->get()->sum(fn ($advance) => (float) (string) $advance->outstanding()),
            'payslips' => $employee->payslips()->with('payrollRun')->latest('id')->limit(12)->get(),
            'leaveRequests' => $employee->leaveRequests()->with('leaveType')->latest('from_date')->limit(10)->get(),
        ]);
    }

    public function edit(Employee $employee): View
    {
        $this->authorize('update', $employee);

        return view('hr.employees.form', $this->formData($employee->load('salaryComponents')));
    }

    public function update(SaveEmployeeRequest $request, Employee $employee, SaveEmployeeAction $save): RedirectResponse
    {
        $this->authorize('update', $employee);

        $save->handle($request->validated(), $employee);

        return redirect()->route('hr.employees.show', $employee)->with('success', "{$employee->full_name} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Employee $employee): array
    {
        $linked = Employee::query()->whereNotNull('user_id')->when($employee->exists, fn ($query) => $query->whereKeyNot($employee->id))->pluck('user_id');

        return [
            'employee' => $employee,
            'types' => EmploymentType::options(),
            'shifts' => Shift::options(),
            'users' => User::query()->active()->whereNotIn('id', $linked)->orderBy('name')->get()->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} ({$user->username})"])->all(),
            'components' => SalaryComponent::query()->where(fn ($query) => $query->active()->orWhereIn('id', $employee->exists ? $employee->salaryComponents->pluck('id') : []))->orderBy('type')->orderBy('name')->get(),
            'assigned' => $employee->exists ? $employee->salaryComponents->mapWithKeys(fn (SalaryComponent $component) => [$component->id => $component->getRelation('pivot')->getAttribute('value_override')])->all() : [],
        ];
    }
}
