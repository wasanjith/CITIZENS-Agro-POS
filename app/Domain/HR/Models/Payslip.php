<?php

namespace App\Domain\HR\Models;

use App\Domain\Finance\Enums\PaidFrom;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\HR\Policies\PayrollPolicy;
use App\Domain\Inventory\Support\StockReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One employee's pay for the month of a payroll run (a snapshot; see the migration).
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property string $employee_name
 * @property string|null $employee_name_si
 * @property string|null $designation
 * @property string|null $epf_no
 * @property bool $is_epf_member
 * @property string $basic
 * @property string $working_days
 * @property string $days_worked
 * @property string $paid_leave_days
 * @property string $no_pay_days
 * @property string $no_pay_deduction
 * @property string $allowances
 * @property string $ot_hours
 * @property string $ot_rate
 * @property string $ot_amount
 * @property string $gross
 * @property string $epf_base
 * @property string $epf_employee
 * @property string $epf_employer
 * @property string $etf
 * @property string $advance_deduction
 * @property string $other_deductions
 * @property string $total_deductions
 * @property string $net
 * @property string|null $no_pay_days_override
 * @property string|null $ot_hours_override
 * @property string|null $advance_override
 * @property string|null $note
 * @property Carbon|null $paid_at
 * @property PaidFrom|null $paid_from
 * @property int|null $bank_account_id
 * @property int|null $drawer_session_id
 * @property string|null $payment_reference
 * @property int|null $paid_by
 */
#[UsePolicy(PayrollPolicy::class)]
class Payslip extends Model implements StockReference
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payroll_run_id' => 'integer',
            'employee_id' => 'integer',
            'is_epf_member' => 'boolean',
            'basic' => 'decimal:2',
            'working_days' => 'decimal:1',
            'days_worked' => 'decimal:1',
            'paid_leave_days' => 'decimal:1',
            'no_pay_days' => 'decimal:1',
            'no_pay_deduction' => 'decimal:2',
            'allowances' => 'decimal:2',
            'ot_hours' => 'decimal:2',
            'ot_rate' => 'decimal:2',
            'ot_amount' => 'decimal:2',
            'gross' => 'decimal:2',
            'epf_base' => 'decimal:2',
            'epf_employee' => 'decimal:2',
            'epf_employer' => 'decimal:2',
            'etf' => 'decimal:2',
            'advance_deduction' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net' => 'decimal:2',
            'no_pay_days_override' => 'decimal:1',
            'ot_hours_override' => 'decimal:2',
            'advance_override' => 'decimal:2',
            'paid_at' => 'datetime',
            'paid_from' => PaidFrom::class,
            'bank_account_id' => 'integer',
            'drawer_session_id' => 'integer',
            'paid_by' => 'integer',
        ];
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function referenceLabel(): string
    {
        return "Salary {$this->loadMissing('payrollRun')->payrollRun->month} {$this->employee_name}";
    }

    public function referenceUrl(): ?string
    {
        return route('hr.payslips.show', $this);
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * @return HasMany<PayslipLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class);
    }

    /**
     * @return HasMany<SalaryAdvanceRecovery, $this>
     */
    public function recoveries(): HasMany
    {
        return $this->hasMany(SalaryAdvanceRecovery::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
