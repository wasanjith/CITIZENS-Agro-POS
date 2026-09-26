<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Finance\Enums\PaidFrom;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Inventory\Support\StockReference;
use App\Domain\Purchasing\Policies\SupplierPaymentPolicy;
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
 * Money the shop paid a supplier, applied to their goods receipts (oldest first or as
 * chosen). reversed_at is set when the cheque bounced or was cancelled.
 *
 * @property int $id
 * @property string $number
 * @property int $supplier_id
 * @property Carbon $date
 * @property string $amount
 * @property PaidFrom $paid_from
 * @property int|null $bank_account_id
 * @property int|null $cheque_id
 * @property int|null $drawer_session_id
 * @property string|null $reference
 * @property string|null $note
 * @property Carbon|null $reversed_at
 * @property int $created_by
 * @property Carbon $created_at
 */
#[Fillable(['number', 'supplier_id', 'date', 'amount', 'paid_from', 'bank_account_id', 'cheque_id', 'drawer_session_id', 'reference', 'note', 'created_by'])]
#[UsePolicy(SupplierPaymentPolicy::class)]
class SupplierPayment extends Model implements StockReference
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'date' => 'date',
            'amount' => 'decimal:2',
            'paid_from' => PaidFrom::class,
            'bank_account_id' => 'integer',
            'cheque_id' => 'integer',
            'drawer_session_id' => 'integer',
            'reversed_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'supplier_id', 'amount', 'paid_from', 'reference', 'reversed_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function referenceLabel(): string
    {
        return $this->number;
    }

    public function referenceUrl(): ?string
    {
        return route('purchasing.supplier-payments.show', $this);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return HasMany<SupplierPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<Cheque, $this>
     */
    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
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
}
