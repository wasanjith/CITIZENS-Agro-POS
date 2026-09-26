<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Enums\AttendanceStatus;
use App\Domain\HR\Models\Attendance;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\Holiday;
use App\Domain\HR\Models\LeaveType;
use App\Domain\HR\Services\AttendanceService;
use App\Domain\HR\Services\LeaveService;
use App\Domain\HR\Services\PayrollCalculator;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Http\Controllers\Concerns\ChoosesPeriod;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Clock in / out, my attendance, the daily sheet, the monthly grid and corrections.
 */
class AttendanceController extends Controller
{
    use ChoosesPeriod;

    public function mine(Request $request, AttendanceService $attendance, LeaveService $leave, PayrollCalculator $calculator): View
    {
        $this->authorize('viewOwn', Attendance::class);

        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        $start = $this->chosenMonth($request);

        return view('hr.attendance.mine', [
            'employee' => $employee,
            'start' => $start,
            'today' => $employee ? $attendance->forDay($employee, today()) : null,
            'rows' => $employee ? $this->monthRows($employee, $start) : [],
            'summary' => $employee ? $calculator->days($employee, $start, $start->copy()->endOfMonth()->min(today())) : null,
            'balances' => $employee ? $leave->balances($employee, today()->year) : collect(),
            'requests' => $employee ? $employee->leaveRequests()->with('leaveType')->latest('from_date')->limit(10)->get() : collect(),
            'leaveTypes' => LeaveType::options(),
        ]);
    }

    public function clockIn(Request $request, AttendanceService $attendance, CurrentTerminal $terminal): RedirectResponse
    {
        $this->authorize('viewOwn', Attendance::class);

        $employee = $this->ownEmployee($request);

        if ($terminal->get() === null) {
            throw ValidationException::withMessages(['attendance' => 'Clock in on one of the shop terminals.']);
        }

        $row = $attendance->clockIn($employee, $terminal->get());

        return back()->with('success', 'Clocked in at '.$row->clock_in?->format('H:i').'.');
    }

    public function clockOut(Request $request, AttendanceService $attendance): RedirectResponse
    {
        $this->authorize('viewOwn', Attendance::class);

        $row = $attendance->clockOut($this->ownEmployee($request));

        return back()->with('success', 'Clocked out at '.$row->clock_out?->format('H:i').'. Good night!');
    }

    /**
     * Daily sheet: everyone employed on the day with their times.
     */
    public function index(Request $request, AttendanceService $attendance): View
    {
        $this->authorize('viewAny', Attendance::class);

        $date = $this->dateInput($request, 'date') ?? today();
        $employees = Employee::query()->employedBetween($date, $date)->where(fn ($query) => $query->where('is_active', true)->orWhereNotNull('leave_date'))->with('shift')->orderBy('full_name')->get();
        $rows = Attendance::query()->whereDate('date', $date->toDateString())->with(['editedBy', 'terminal', 'leaveRequest.leaveType'])->get()->keyBy('employee_id');

        return view('hr.attendance.index', [
            'date' => $date,
            'employees' => $employees,
            'rows' => $rows,
            'holiday' => Holiday::query()->whereDate('date', $date->toDateString())->value('name'),
            'isWorkingDay' => fn (Employee $employee) => $attendance->isWorkingDay($employee->workingShift(), $date),
            'statuses' => AttendanceStatus::options(),
        ]);
    }

    /**
     * Monthly grid: employees down, days across.
     */
    public function month(Request $request, PayrollCalculator $calculator): View
    {
        $this->authorize('viewAny', Attendance::class);

        $start = $this->chosenMonth($request);
        $end = $start->copy()->endOfMonth()->startOfDay();
        $employees = Employee::query()->employedBetween($start, $end)->where(fn ($query) => $query->where('is_active', true)->orWhereDate('leave_date', '>=', $start))->with('shift')->orderBy('full_name')->get();
        $rows = Attendance::query()->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()
            ->groupBy('employee_id')
            ->map(fn ($days) => $days->keyBy(fn (Attendance $row) => $row->date->toDateString()));

        return view('hr.attendance.month', [
            'start' => $start,
            'end' => $end,
            'employees' => $employees,
            'rows' => $rows,
            'holidays' => Holiday::between($start, $end),
            'summaries' => $employees->mapWithKeys(fn (Employee $employee) => [$employee->id => $calculator->days($employee, $start, $end->copy()->min(today()))]),
        ]);
    }

    public function update(Request $request, AttendanceService $attendance): RedirectResponse
    {
        $this->authorize('update', Attendance::class);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'clock_in' => ['nullable', 'date_format:H:i'],
            'clock_out' => ['nullable', 'date_format:H:i'],
            'edit_reason' => ['required', 'string', 'max:200'],
        ]);

        $employee = Employee::withTrashed()->findOrFail($validated['employee_id']);
        $row = $attendance->correct($employee, Carbon::parse($validated['date'])->startOfDay(), $validated, $request->user(), $validated['edit_reason']);

        return back()->with('success', "{$employee->full_name}, {$row->date->format('Y-m-d')}: saved as {$row->status->label()}.");
    }

    public function photo(Attendance $attendance): StreamedResponse
    {
        $this->authorize('viewAny', Attendance::class);

        abort_if($attendance->photo_path === null || ! Storage::disk('local')->exists($attendance->photo_path), 404);

        return Storage::disk('local')->response($attendance->photo_path);
    }

    /**
     * @return array<string, array{date: Carbon, row: Attendance|null, holiday: string|null, working: bool}>
     */
    private function monthRows(Employee $employee, Carbon $start): array
    {
        $end = $start->copy()->endOfMonth()->startOfDay();
        $shift = $employee->workingShift();
        $holidays = Holiday::between($start, $end);
        $rows = $employee->attendances()->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->keyBy(fn (Attendance $row) => $row->date->toDateString());
        $days = [];

        for ($day = $start->copy(); $day->lte($end) && $day->lte(today()); $day->addDay()) {
            $key = $day->toDateString();
            $days[$key] = [
                'date' => $day->copy(),
                'row' => $rows->get($key),
                'holiday' => $holidays[$key] ?? null,
                'working' => ($shift === null || $shift->worksOn($day)) && ! isset($holidays[$key]),
            ];
        }

        return array_reverse($days);
    }

    private function chosenMonth(Request $request): Carbon
    {
        $month = (string) $request->query('month', '');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
            ? Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay()
            : today()->startOfMonth();
    }

    private function ownEmployee(Request $request): Employee
    {
        return Employee::query()->active()->where('user_id', $request->user()->id)->first()
            ?? throw ValidationException::withMessages(['attendance' => 'Your login is not linked to an employee. Ask the owner.']);
    }
}
