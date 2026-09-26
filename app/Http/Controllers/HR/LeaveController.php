<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Enums\LeaveStatus;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\LeaveRequest;
use App\Domain\HR\Models\LeaveType;
use App\Domain\HR\Services\LeaveService;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Leave requests: staff ask for their own leave, the owner approves or rejects, or
 * enters leave for anyone.
 */
class LeaveController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $requests = $this->applyListQuery(
            LeaveRequest::query()->with(['employee', 'leaveType', 'requestedBy', 'decidedBy']),
            $request,
            sortable: ['from_date', 'created_at'],
            filters: [
                'status' => function (Builder $query, string $status): void {
                    $query->where('status', $status);
                },
                'employee' => function (Builder $query, string $employee): void {
                    $query->where('employee_id', (int) $employee);
                },
            ],
            defaultSort: 'from_date',
        )->paginate(50)->withQueryString();

        return view('hr.leave.index', [
            'requests' => $requests,
            'waiting' => LeaveRequest::query()->where('status', LeaveStatus::Pending)->count(),
            'statuses' => LeaveStatus::options(),
            'employees' => Employee::options(),
            'types' => LeaveType::options(),
        ]);
    }

    /**
     * The owner enters leave for an employee (approved straight away unless unticked).
     */
    public function store(Request $request, LeaveService $leave): RedirectResponse
    {
        $this->authorize('create', LeaveRequest::class);

        $validated = $this->validated($request, ['employee_id' => ['required', 'integer'], 'approve' => ['nullable', 'boolean']]);
        $created = $leave->request($validated, $request->user(), $request->boolean('approve'));

        return back()->with('success', "Leave for {$created->employee->full_name} ({$created->periodLabel()}, {$created->days} days) ".($created->refresh()->status === LeaveStatus::Approved ? 'approved.' : 'saved.'));
    }

    /**
     * A member of staff asks for their own leave.
     */
    public function requestOwn(Request $request, LeaveService $leave): RedirectResponse
    {
        $this->authorize('requestOwn', LeaveRequest::class);

        $employee = Employee::query()->active()->where('user_id', $request->user()->id)->first()
            ?? throw ValidationException::withMessages(['leave' => 'Your login is not linked to an employee. Ask the owner.']);

        $created = $leave->request([...$this->validated($request), 'employee_id' => $employee->id], $request->user());

        return back()->with('success', "Leave requested for {$created->periodLabel()} ({$created->days} days). The owner will approve it.");
    }

    public function approve(Request $request, LeaveRequest $leaveRequest, LeaveService $leave): RedirectResponse
    {
        $this->authorize('decide', LeaveRequest::class);

        $leave->approve($leaveRequest, $request->user(), $request->string('note')->toString() ?: null);

        return back()->with('success', "Leave for {$leaveRequest->employee->full_name} approved.");
    }

    public function reject(Request $request, LeaveRequest $leaveRequest, LeaveService $leave): RedirectResponse
    {
        $this->authorize('decide', LeaveRequest::class);

        $leave->reject($leaveRequest, $request->user(), $request->string('note')->toString() ?: null);

        return back()->with('success', "Leave for {$leaveRequest->employee->full_name} rejected.");
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest, LeaveService $leave): RedirectResponse
    {
        abort_unless($request->user()->can('decide', LeaveRequest::class) || $request->user()->can('withdraw', $leaveRequest), 403);

        $leave->cancel($leaveRequest, $request->user(), $request->string('note')->toString() ?: null);

        return back()->with('success', 'Leave cancelled.');
    }

    /**
     * @param  array<string, list<mixed>>  $extra
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $extra = []): array
    {
        return $request->validate([
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'from_date' => ['required', 'date', 'after_or_equal:'.today()->subDays(60)->toDateString()],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'half_day' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            ...$extra,
        ]);
    }
}
