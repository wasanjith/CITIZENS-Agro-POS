<?php

namespace App\Domain\Customers\Models;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Customers\Policies\CustomerPaymentPolicy;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Inventory\Support\StockReference;
use App\Domain\Sales\Enums\PaymentMethod;
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
 * Money a customer paid towards their account at the main cashier. Cash goes into the
 * cashier's drawer session. Allocations say which credit invoices it paid; any amount
 * not allocated stays on the account as an advance.
 *
 * @property int $id
 * @property string $number
 * @property int $customer_id
 * @property Carbon $date
 * @property string $amount
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property int|null $cheque_id
 * @property int|null $bank_account_id
 * @property Carbon|null $reversed_at
 * @property int|null $drawer_session_id
 * @property int|null $terminal_id
 * @property int $received_by
 * @property string|null $note
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 */
#[Fillable(['number', 'customer_id', 'date', 'amount', 'method', 'reference', 'cheque_id', 'bank_account_id', 'drawer_session_id', 'terminal_id', 'received_by', 'note', 'idempotency_key'])]
#[UsePolicy(CustomerPaymentPolicy::class)]
class CustomerPayment extends Model implements StockReference
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'date' => 'date',
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'cheque_id' => 'integer',
            'bank_account_id' => 'integer',
            'reversed_at' => 'datetime',
            'drawer_session_id' => 'integer',
            'terminal_id' => 'integer',
            'received_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'customer_id', 'amount', 'method', 'reference'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return HasMany<CustomerPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
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
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function referenceLabel(): string
    {
        return $this->number;
    }

    public function referenceUrl(): ?string
    {
        return route('customers.payments.show', $this);
    }
}
