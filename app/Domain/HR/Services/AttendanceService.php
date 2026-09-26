<?php

namespace App\Domain\HR\Services;

use App\Domain\HR\Enums\AttendanceSource;
use App\Domain\HR\Enums\AttendanceStatus;
use App\Domain\HR\Models\Attendance;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\Holiday;
use App\Domain\HR\Models\Shift;
use App\Domain\Identity\Models\Terminal;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Clock in / clock out and manual corrections. Late, early-leave and overtime minutes
 * are worked out from the employee's shift (Settings → HR for the overtime minimum):
 *
 *  - late: clocked in after start + grace minutes → minutes after the start time
 *  - early leave: clocked out before the end time → minutes before the end
 *  - overtime: minutes after the end time (at least the minimum, else none); on a day
 *    off or a holiday every minute worked is overtime
 */
class AttendanceService
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * First sign-in of the day. A second sign-in the same day changes nothing.
     */
    public function clockIn(Employee $employee, ?Terminal $terminal = null, ?string $photoDataUrl = null, ?Carbon $at = null): Attendance
    {
        $at ??= now();
        $date = $at->copy()->startOfDay();

        $attendance = $this->forDay($employee, $date);

        if ($attendance?->clock_in !== null) {
            return $attendance;
        }

        $attendance ??= new Attendance(['employee_id' => $employee->id, 'date' => $date]);
        $attendance->fill([
            'clock_in' => $at->copy()->startOfMinute(),
            'clock_out' => null,
            'source' => AttendanceSource::Pos,
            'terminal_id' => $terminal?->id,
            'status' => AttendanceStatus::Present,
            'photo_path' => $this->storePhoto($photoDataUrl, $employee, $date),
        ]);
        $this->applyMinutes($attendance, $employee);

        try {
            $attendance->save();
        } catch (UniqueConstraintViolationException) {
            // Two sign-ins at the same moment: the other one created the row.
            return $this->forDay($employee, $date) ?? throw ValidationException::withMessages(['attendance' => 'Could not clock in. Try again.']);
        }

        return $attendance;
    }

    public function clockOut(Employee $employee, ?Carbon $at = null): Attendance
    {
        $at ??= now();

        return DB::transaction(function () use ($employee, $at): Attendance {
            $attendance = Attendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', $at->toDateString())
                ->lockForUpdate()
                ->first();

            if ($attendance?->clock_in === null) {
                throw ValidationException::withMessages(['attendance' => 'You have not clocked in today.']);
            }

            if ($attendance->clock_out !== null) {
                throw ValidationException::withMessages(['attendance' => 'You already clocked out at '.$attendance->clock_out->format('H:i').'.']);
            }

            $attendance->clock_out = $at->copy()->startOfMinute();
            $this->applyMinutes($attendance, $employee);
            $attendance->save();

            return $attendance;
        });
    }

    /**
     * The owner corrects (or enters) a day by hand. The reason is kept with the row and
     * the change goes to the audit log.
     *
     * @param  array{status: string, clock_in?: string|null, clock_out?: string|null}  $data  times as H:i
     */
    public function correct(Employee $employee, Carbon $date, array $data, User $user, string $reason): Attendance
    {
        $reason = trim($reason);
        $status = AttendanceStatus::tryFrom($data['status']);

        if ($status === null) {
            throw ValidationException::withMessages(['status' => 'Choose the status.']);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['edit_reason' => 'Enter the reason for the change.']);
        }

        if ($date->isAfter(today())) {
            throw ValidationException::withMessages(['date' => 'Attendance cannot be entered for a future day.']);
        }

        $clockIn = $status->hasTimes() && filled($data['clock_in'] ?? null) ? $date->copy()->setTimeFromTimeString((string) $data['clock_in']) : null;
        $clockOut = $status->hasTimes() && filled($data['clock_out'] ?? null) ? $date->copy()->setTimeFromTimeString((string) $data['clock_out']) : null;

        if ($status->hasTimes() && $clockIn === null) {
            throw ValidationException::withMessages(['clock_in' => 'Enter the time they came in.']);
        }

        if ($clockIn !== null && $clockOut !== null && $clockOut->lte($clockIn)) {
            throw ValidationException::withMessages(['clock_out' => 'The time out must be after the time in.']);
        }

        return DB::transaction(function () use ($employee, $date, $status, $clockIn, $clockOut, $user, $reason): Attendance {
            $attendance = Attendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date->toDateString())
                ->lockForUpdate()
                ->first()
                ?? new Attendance(['employee_id' => $employee->id, 'date' => $date->copy()->startOfDay(), 'source' => AttendanceSource::Manual]);

            $attendance->fill([
                'status' => $status,
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                'edited_by' => $user->id,
                'edit_reason' => mb_substr($reason, 0, 255),
            ]);

            if (! $status->hasTimes() && $status !== AttendanceStatus::Leave) {
                $attendance->leave_request_id = null;
            }

            $this->applyMinutes($attendance, $employee);
            $attendance->save();

            return $attendance;
        });
    }

    public function forDay(Employee $employee, Carbon $date): ?Attendance
    {
        return Attendance::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->first();
    }

    /**
     * Today's open attendance (clocked in, not out) of the signed-in user, if they are an employee.
     */
    public function openToday(User $user): ?Attendance
    {
        $employee = Employee::query()->active()->where('user_id', $user->id)->first();

        if ($employee === null) {
            return null;
        }

        $attendance = $this->forDay($employee, today());

        return $attendance?->clock_in !== null && $attendance->clock_out === null ? $attendance : null;
    }

    public function isWorkingDay(?Shift $shift, Carbon $date): bool
    {
        return ($shift === null || $shift->worksOn($date)) && ! Holiday::query()->whereDate('date', $date->toDateString())->exists();
    }

    /**
     * Fill late / early leave / overtime minutes from the times and the shift.
     */
    public function applyMinutes(Attendance $attendance, Employee $employee): void
    {
        $attendance->late_minutes = 0;
        $attendance->early_leave_minutes = 0;
        $attendance->ot_minutes = 0;

        $shift = $employee->workingShift();

        if ($attendance->clock_in === null || $shift === null) {
            return;
        }

        $date = $attendance->date->copy()->startOfDay();
        $minimum = (int) $this->settings->get('hr.ot_min_minutes', 30);
        $in = $attendance->clock_in;
        $out = $attendance->clock_out;

        if (! $this->isWorkingDay($shift, $date)) {
            $worked = $out !== null ? (int) $in->diffInMinutes($out) : 0;
            $attendance->ot_minutes = $worked >= max($minimum, 1) ? $worked : 0;

            return;
        }

        $start = $shift->startOn($date);
        $end = $shift->endOn($date);

        if ($in->gt($start->copy()->addMinutes($shift->grace_minutes))) {
            $attendance->late_minutes = (int) $start->diffInMinutes($in);
        }

        if ($out === null) {
            return;
        }

        if ($out->lt($end)) {
            $attendance->early_leave_minutes = (int) $out->diffInMinutes($end);
        }

        $overtime = $out->gt($end) ? (int) $end->diffInMinutes($out) : 0;
        $attendance->ot_minutes = $overtime >= $minimum ? $overtime : 0;
    }

    /**
     * A webcam snapshot (data:image/jpeg;base64,…) taken on the PIN screen, when switched
     * on in Settings → HR. Anything that is not a small JPEG/PNG is ignored.
     */
    private function storePhoto(?string $dataUrl, Employee $employee, Carbon $date): ?string
    {
        if ($dataUrl === null || ! (bool) $this->settings->get('hr.clock_in_photo', false)) {
            return null;
        }

        if (! preg_match('#^data:image/(jpeg|png);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match) || strlen($match[2]) > 700_000) {
            return null;
        }

        $bytes = base64_decode($match[2], true);

        if ($bytes === false || @getimagesizefromstring($bytes) === false) {
            return null;
        }

        $path = 'attendance-photos/'.$date->format('Y/m').'/'.$employee->id.'-'.$date->format('d').'-'.bin2hex(random_bytes(4)).'.'.($match[1] === 'png' ? 'png' : 'jpg');
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }
}
