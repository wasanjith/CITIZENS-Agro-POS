<?php

namespace App\Domain\HR\Services;

use App\Domain\HR\Enums\AttendanceSource;
use App\Domain\HR\Enums\AttendanceStatus;
use App\Domain\HR\Enums\LeaveStatus;
use App\Domain\HR\Models\Attendance;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\Holiday;
use App\Domain\HR\Models\LeaveRequest;
use App\Domain\HR\Models\LeaveType;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave requests, approvals and balances. Leave counts working days only (the
 * employee's shift days, less holidays). An approved leave writes LEAVE rows into the
 * attendance (half-day leave a HALF_DAY row), which is what payroll reads.
 */
class LeaveService
{
    /**
     * @param  array{employee_id: int|string, leave_type_id: int|string, from_date: string, to_date?: string|null, half_day?: bool|string|null, reason?: string|null}  $data
     */
    public function request(array $data, User $user, bool $approve = false): LeaveRequest
    {
        $employee = Employee::query()->active()->find((int) $data['employee_id']);
        $type = LeaveType::query()->active()->find((int) $data['leave_type_id']);

        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'Choose the employee.']);
        }

        if ($type === null) {
            throw ValidationException::withMessages(['leave_type_id' => 'Choose the type of leave.']);
        }

        $from = Carbon::parse($data['from_date'])->startOfDay();
        $halfDay = filter_var($data['half_day'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $to = $halfDay || blank($data['to_date'] ?? null) ? $from->copy() : Carbon::parse((string) $data['to_date'])->startOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to_date' => 'The last day cannot be before the first day.']);
        }

        if ($from->diffInDays($to) > 60) {
            throw ValidationException::withMessages(['to_date' => 'Leave can be at most 60 days at a time.']);
        }

        $days = $this->countDays($employee, $from, $to, $halfDay);

        if (! $days->isPositive()) {
            throw ValidationException::withMessages(['from_date' => 'Those days are not working days (day off or holiday).']);
        }

        $overlap = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved])
            ->whereDate('from_date', '<=', $to)
            ->whereDate('to_date', '>=', $from)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['from_date' => 'There is already leave for these days.']);
        }

        return DB::transaction(function () use ($employee, $type, $from, $to, $halfDay, $days, $data, $user, $approve): LeaveRequest {
            $request = LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'from_date' => $from,
                'to_date' => $to,
                'half_day' => $halfDay,
                'days' => (string) $days,
                'reason' => filled($data['reason'] ?? null) ? mb_substr((string) $data['reason'], 0, 255) : null,
                'status' => LeaveStatus::Pending,
                'requested_by' => $user->id,
            ]);

            if ($approve) {
                $this->approve($request, $user);
            }

            return $request;
        });
    }

    public function approve(LeaveRequest $request, User $user, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $user, $note): LeaveRequest {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertPending($request);

            $employee = $request->employee()->firstOrFail();
            $type = $request->leaveType()->firstOrFail();
            $balance = $this->balances($employee, $request->from_date->year)->firstWhere('type.id', $type->id);

            if ($balance !== null && $type->hasLimit() && Money::of($request->days)->isGreaterThan($balance['remaining'])) {
                throw ValidationException::withMessages(['leave' => "{$employee->full_name} has only {$balance['remaining']} days of {$type->name} left this year. Use no-pay leave for the rest."]);
            }

            foreach ($this->workingDays($employee, $request->from_date, $request->to_date) as $day) {
                $attendance = Attendance::query()->where('employee_id', $employee->id)->whereDate('date', $day->toDateString())->lockForUpdate()->first();

                // A day they actually worked stays as it is.
                if ($attendance?->clock_in !== null) {
                    continue;
                }

                $attendance ??= new Attendance(['employee_id' => $employee->id, 'date' => $day]);
                $attendance->fill([
                    'source' => AttendanceSource::Leave,
                    'status' => $request->half_day ? AttendanceStatus::HalfDay : AttendanceStatus::Leave,
                    'leave_request_id' => $request->id,
                    'late_minutes' => 0,
                    'early_leave_minutes' => 0,
                    'ot_minutes' => 0,
                ])->save();
            }

            $request->forceFill([
                'status' => LeaveStatus::Approved,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => filled($note) ? mb_substr((string) $note, 0, 255) : null,
            ])->save();

            return $request;
        });
    }

    public function reject(LeaveRequest $request, User $user, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $user, $note): LeaveRequest {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertPending($request);

            $request->forceFill([
                'status' => LeaveStatus::Rejected,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => filled($note) ? mb_substr((string) $note, 0, 255) : null,
            ])->save();

            return $request;
        });
    }

    /**
     * Withdraw a waiting request, or cancel approved leave (its attendance rows go, so
     * the days count as absent again unless they are corrected).
     */
    public function cancel(LeaveRequest $request, User $user, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $user, $note): LeaveRequest {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! in_array($request->status, [LeaveStatus::Pending, LeaveStatus::Approved], true)) {
                throw ValidationException::withMessages(['leave' => 'This request is already '.strtolower($request->status->label()).'.']);
            }

            Attendance::query()->where('leave_request_id', $request->id)->whereNull('clock_in')->delete();

            $request->forceFill([
                'status' => LeaveStatus::Cancelled,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => filled($note) ? mb_substr((string) $note, 0, 255) : $request->decision_note,
            ])->save();

            return $request;
        });
    }

    public function countDays(Employee $employee, Carbon $from, Carbon $to, bool $halfDay = false): BigDecimal
    {
        $days = count($this->workingDays($employee, $from, $to));

        return BigDecimal::of($halfDay && $days > 0 ? '0.5' : (string) $days)->toScale(1);
    }

    /**
     * Working days between two dates for this employee (shift days, not holidays).
     *
     * @return list<Carbon>
     */
    public function workingDays(Employee $employee, Carbon $from, Carbon $to): array
    {
        $shift = $employee->workingShift();
        $holidays = Holiday::between($from, $to);
        $days = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            if (($shift === null || $shift->worksOn($day)) && ! isset($holidays[$day->toDateString()])) {
                $days[] = $day->copy();
            }
        }

        return $days;
    }

    /**
     * Leave taken and left per type for one calendar year.
     *
     * @return Collection<int, array{type: LeaveType, entitled: string|null, taken: string, pending: string, remaining: string|null}>
     */
    public function balances(Employee $employee, int $year): Collection
    {
        $requests = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [LeaveStatus::Approved, LeaveStatus::Pending])
            ->whereYear('from_date', $year)
            ->get(['leave_type_id', 'status', 'days']);

        return LeaveType::query()->active()->orderBy('id')->get()->map(fn (LeaveType $type) => $this->balance($type, $requests))->values();
    }

    /**
     * @param  EloquentCollection<int, LeaveRequest>  $requests
     * @return array{type: LeaveType, entitled: string|null, taken: string, pending: string, remaining: string|null}
     */
    private function balance(LeaveType $type, EloquentCollection $requests): array
    {
        $sum = fn (LeaveStatus $status): BigDecimal => $requests
            ->where('leave_type_id', $type->id)
            ->where('status', $status)
            ->reduce(fn (BigDecimal $total, LeaveRequest $request) => $total->plus($request->days), BigDecimal::of('0.0'));

        $taken = $sum(LeaveStatus::Approved);

        return [
            'type' => $type,
            'entitled' => $type->hasLimit() ? (string) $type->days_per_year : null,
            'taken' => (string) $taken,
            'pending' => (string) $sum(LeaveStatus::Pending),
            'remaining' => $type->hasLimit() ? (string) BigDecimal::of($type->days_per_year)->minus($taken)->toScale(1) : null,
        ];
    }

    private function assertPending(LeaveRequest $request): void
    {
        if ($request->status !== LeaveStatus::Pending) {
            throw ValidationException::withMessages(['leave' => 'This request is already '.strtolower($request->status->label()).'.']);
        }
    }
}
