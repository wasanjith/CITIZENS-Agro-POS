<?php

namespace App\Domain\HR\Models;

use App\Domain\HR\Enums\AttendanceSource;
use App\Domain\HR\Enums\AttendanceStatus;
use App\Domain\HR\Policies\AttendancePolicy;
use App\Domain\Identity\Models\Terminal;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One employee on one day.
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $date
 * @property Carbon|null $clock_in
 * @property Carbon|null $clock_out
 * @property AttendanceSource $source
 * @property int|null $terminal_id
 * @property string|null $photo_path
 * @property int $late_minutes
 * @property int $early_leave_minutes
 * @property int $ot_minutes
 * @property AttendanceStatus $status
 * @property int|null $leave_request_id
 * @property int|null $edited_by
 * @property string|null $edit_reason
 */
#[Fillable(['employee_id', 'date', 'clock_in', 'clock_out', 'source', 'terminal_id', 'photo_path', 'late_minutes', 'early_leave_minutes', 'ot_minutes', 'status', 'leave_request_id', 'edited_by', 'edit_reason'])]
#[UsePolicy(AttendancePolicy::class)]
class Attendance extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'date' => 'date',
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'source' => AttendanceSource::class,
            'terminal_id' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'ot_minutes' => 'integer',
            'status' => AttendanceStatus::class,
            'leave_request_id' => 'integer',
            'edited_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['date', 'clock_in', 'clock_out', 'status', 'late_minutes', 'ot_minutes', 'edit_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Clocked in on a past day and never clocked out.
     */
    public function missingClockOut(): bool
    {
        return $this->clock_in !== null && $this->clock_out === null && $this->date->lt(today());
    }

    public function workedMinutes(): int
    {
        return $this->clock_in !== null && $this->clock_out !== null ? (int) $this->clock_in->diffInMinutes($this->clock_out) : 0;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /**
     * @return BelongsTo<LeaveRequest, $this>
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }
}
