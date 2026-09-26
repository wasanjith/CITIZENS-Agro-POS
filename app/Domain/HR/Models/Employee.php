<?php

namespace App\Domain\HR\Models;

use App\Domain\HR\Enums\EmploymentType;
use App\Domain\HR\Policies\EmployeePolicy;
use App\Models\User;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A member of staff. Linked to a user login, the first PIN sign-in of the day clocks
 * them in.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $code
 * @property string $full_name
 * @property string|null $name_si
 * @property string|null $nic
 * @property Carbon|null $dob
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $designation
 * @property Carbon $join_date
 * @property Carbon|null $leave_date
 * @property EmploymentType $employment_type
 * @property int|null $shift_id
 * @property string $basic_salary
 * @property string|null $bank_name
 * @property string|null $bank_account_no
 * @property string|null $epf_no
 * @property bool $is_epf_member
 * @property bool $is_active
 */
#[Fillable(['user_id', 'code', 'full_name', 'name_si', 'nic', 'dob', 'phone', 'address', 'designation', 'join_date', 'leave_date', 'employment_type', 'shift_id', 'basic_salary', 'bank_name', 'bank_account_no', 'epf_no', 'is_epf_member', 'is_active'])]
#[UsePolicy(EmployeePolicy::class)]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'dob' => 'date',
            'join_date' => 'date',
            'leave_date' => 'date',
            'employment_type' => EmploymentType::class,
            'shift_id' => 'integer',
            'basic_salary' => 'decimal:2',
            'is_epf_member' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['full_name', 'user_id', 'designation', 'join_date', 'leave_date', 'employment_type', 'shift_id', 'basic_salary', 'bank_account_no', 'epf_no', 'is_epf_member', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @param  Builder<Employee>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Employed at some time between the two dates.
     *
     * @param  Builder<Employee>  $query
     */
    public function scopeEmployedBetween(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->whereDate('join_date', '<=', $to)
            ->where(fn (Builder $inner) => $inner->whereNull('leave_date')->orWhereDate('leave_date', '>=', $from));
    }

    public function isEmployedOn(Carbon $date): bool
    {
        return $this->join_date->lte($date) && ($this->leave_date === null || $this->leave_date->gte($date));
    }

    public function displayName(): string
    {
        return "{$this->code} {$this->full_name}";
    }

    /**
     * The employee's shift, or the shop's default shift.
     */
    public function workingShift(): ?Shift
    {
        return $this->shift ?? Shift::default();
    }

    /**
     * @return array<int, string>
     */
    public static function options(bool $activeOnly = true): array
    {
        return self::query()
            ->when($activeOnly, fn (Builder $query) => $query->active())
            ->orderBy('full_name')
            ->get(['id', 'code', 'full_name'])
            ->mapWithKeys(fn (self $employee) => [$employee->id => $employee->displayName()])
            ->all();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * @return HasMany<SalaryAdvance, $this>
     */
    public function advances(): HasMany
    {
        return $this->hasMany(SalaryAdvance::class);
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * @return BelongsToMany<SalaryComponent, $this>
     */
    public function salaryComponents(): BelongsToMany
    {
        return $this->belongsToMany(SalaryComponent::class, 'employee_salary_components')
            ->withPivot('value_override')
            ->withTimestamps();
    }
}
