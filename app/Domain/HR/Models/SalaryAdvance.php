<?php

namespace App\Domain\HR\Models;

use App\Domain\Finance\Enums\PaidFrom;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\HR\Enums\SalaryAdvanceStatus;
use App\Domain\HR\Policies\PayrollPolicy;
use App\Domain\Inventory\Support\StockReference;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Money lent to an employee, recovered from the next payslips.
 *
 * @property int $id
 * @property string $number
 * @property int $employee_id
 * @property Carbon $date
 * @property string $amount
 * @property int $installments
 * @property string $installment_amount
 * @property string $recovered_amount
 * @property SalaryAdvanceStatus $status
 * @property PaidFrom $paid_from
 * @property int|null $bank_account_id
 * @property int|null $drawer_session_id
 * @property string|null $note
 * @property int $created_by
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property Carbon $created_at
 */
#[Fillable(['number', 'employee_id', 'date', 'amount', 'installments', 'installment_amount', 'recovered_amount', 'status', 'paid_from', 'bank_account_id', 'note', 'created_by'])]
#[UsePolicy(PayrollPolicy::class)]
class SalaryAdvance extends Model implements StockReference
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
            'amount' => 'decimal:2',
            'installments' => 'integer',
            'installment_amount' => 'decimal:2',
            'recovered_amount' => 'decimal:2',
            'status' => SalaryAdvanceStatus::class,
            'paid_from' => PaidFrom::class,
            'bank_account_id' => 'integer',
            'drawer_session_id' => 'integer',
            'created_by' => 'integer',
            'cancelled_at' => 'datetime',
            'cancelled_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'employee_id', 'amount', 'installments', 'recovered_amount', 'status', 'cancel_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function outstanding(): BigDecimal
    {
        return Money::of($this->amount)->minus(Money::of($this->recovered_amount));
    }

    /**
     * What the next payslip recovers: one installment, or what is left.
     */
    public function nextInstallment(): BigDecimal
    {
        $outstanding = $this->outstanding();
        $installment = Money::of($this->installment_amount);

        return $installment->isGreaterThan($outstanding) ? $outstanding : $installment;
    }

    public function referenceLabel(): string
    {
        return $this->number;
    }

    public function referenceUrl(): ?string
    {
        return route('hr.advances.show', $this);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return HasMany<SalaryAdvanceRecovery, $this>
     */
    public function recoveries(): HasMany
    {
        return $this->hasMany(SalaryAdvanceRecovery::class);
    }
}
