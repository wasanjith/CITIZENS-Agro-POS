<?php

namespace App\Domain\Finance\Models;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Finance\Enums\PaidFrom;
use App\Domain\Finance\Policies\ExpensePolicy;
use App\Domain\Inventory\Support\StockReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Money spent on running the shop, paid from the drawer (petty cash), the safe or a
 * bank account. Cancelled expenses are kept and reversed, never deleted.
 *
 * @property int $id
 * @property string $number
 * @property Carbon $date
 * @property int $expense_category_id
 * @property string $amount
 * @property PaidFrom $paid_from
 * @property int|null $bank_account_id
 * @property int|null $drawer_session_id
 * @property string|null $payee
 * @property string|null $reference
 * @property string|null $note
 * @property string|null $receipt_path
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property int $created_by
 * @property Carbon $created_at
 */
#[Fillable(['number', 'date', 'expense_category_id', 'amount', 'paid_from', 'bank_account_id', 'drawer_session_id', 'payee', 'reference', 'note', 'receipt_path', 'created_by'])]
#[UsePolicy(ExpensePolicy::class)]
class Expense extends Model implements StockReference
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'expense_category_id' => 'integer',
            'amount' => 'decimal:2',
            'paid_from' => PaidFrom::class,
            'bank_account_id' => 'integer',
            'drawer_session_id' => 'integer',
            'cancelled_at' => 'datetime',
            'cancelled_by' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'date', 'expense_category_id', 'amount', 'paid_from', 'cancelled_at', 'cancel_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function referenceLabel(): string
    {
        return $this->number;
    }

    public function referenceUrl(): ?string
    {
        return route('finance.expenses.show', $this);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<DrawerSession, $this>
     */
    public function drawerSession(): BelongsTo
    {
        return $this->belongsTo(DrawerSession::class);
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
}
