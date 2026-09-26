<?php

namespace App\Domain\HR\Models;

use App\Domain\HR\Enums\LeaveStatus;
use App\Domain\HR\Policies\LeaveRequestPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property Carbon $from_date
 * @property Carbon $to_date
 * @property bool $half_day
 * @property string $days
 * @property string|null $reason
 * @property LeaveStatus $status
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon $created_at
 */
#[Fillable(['employee_id', 'leave_type_id', 'from_date', 'to_date', 'half_day', 'days', 'reason', 'status', 'requested_by'])]
#[UsePolicy(LeaveRequestPolicy::class)]
class LeaveRequest extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'leave_type_id' => 'integer',
            'from_date' => 'date',
            'to_date' => 'date',
            'half_day' => 'boolean',
            'days' => 'decimal:1',
            'status' => LeaveStatus::class,
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'leave_type_id', 'from_date', 'to_date', 'days', 'status', 'decision_note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function periodLabel(): string
    {
        $from = $this->from_date->format('Y-m-d');

        return $this->from_date->equalTo($this->to_date) ? $from.($this->half_day ? ' (half day)' : '') : $from.' → '.$this->to_date->format('Y-m-d');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
