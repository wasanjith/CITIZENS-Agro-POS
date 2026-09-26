<?php

namespace App\Domain\HR\Models;

use App\Domain\HR\Enums\PayrollStatus;
use App\Domain\HR\Policies\PayrollPolicy;
use App\Domain\Inventory\Support\StockReference;
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
 * The salaries of one month.
 *
 * @property int $id
 * @property string $month YYYY-MM
 * @property PayrollStatus $status
 * @property string $total_gross
 * @property string $total_deductions
 * @property string $total_net
 * @property string $total_epf_employee
 * @property string $total_epf_employer
 * @property string $total_etf
 * @property int $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $epf_etf_paid_at
 * @property int|null $epf_etf_paid_by
 * @property string|null $epf_etf_reference
 * @property Carbon $created_at
 */
#[Fillable(['month', 'status', 'created_by'])]
#[UsePolicy(PayrollPolicy::class)]
class PayrollRun extends Model implements StockReference
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayrollStatus::class,
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
            'total_epf_employee' => 'decimal:2',
            'total_epf_employer' => 'decimal:2',
            'total_etf' => 'decimal:2',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'epf_etf_paid_at' => 'datetime',
            'epf_etf_paid_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['month', 'status', 'total_net', 'epf_etf_paid_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function start(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->start()->endOfMonth()->startOfDay();
    }

    public function label(): string
    {
        return $this->start()->format('F Y');
    }

    public function isEditable(): bool
    {
        return $this->status === PayrollStatus::Calculated;
    }

    public function referenceLabel(): string
    {
        return 'Payroll '.$this->month;
    }

    public function referenceUrl(): ?string
    {
        return route('hr.payroll.show', $this);
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function epfEtfPaidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'epf_etf_paid_by');
    }
}
