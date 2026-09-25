<?php

namespace App\Domain\Sales\Models;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Inventory\Support\StockReference;
use App\Domain\Sales\Enums\RefundMethod;
use App\Domain\Sales\Policies\SaleReturnPolicy;
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
 * Goods brought back against a settled invoice. Always linked to the original sale and
 * done at the main cashier (cashier authority, pos.refund). Never edited.
 *
 * @property int $id
 * @property string $number
 * @property int $sale_id
 * @property int|null $customer_id
 * @property string $reason
 * @property RefundMethod $refund_method
 * @property string $total
 * @property string $cost_total
 * @property int|null $terminal_id
 * @property int|null $drawer_session_id
 * @property int $created_by
 * @property int $approved_by
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 */
#[Fillable(['number', 'sale_id', 'customer_id', 'reason', 'refund_method', 'total', 'cost_total', 'terminal_id', 'drawer_session_id', 'created_by', 'approved_by', 'idempotency_key'])]
#[UsePolicy(SaleReturnPolicy::class)]
class SaleReturn extends Model implements StockReference
{
    use LogsActivity;

    /**
     * Fields that reveal the shop's buying cost.
     */
    public const COST_FIELDS = ['cost_total'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_id' => 'integer',
            'customer_id' => 'integer',
            'refund_method' => RefundMethod::class,
            'total' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'terminal_id' => 'integer',
            'drawer_session_id' => 'integer',
            'created_by' => 'integer',
            'approved_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'sale_id', 'refund_method', 'total', 'reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return HasMany<SaleReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * @return BelongsTo<DrawerSession, $this>
     */
    public function drawerSession(): BelongsTo
    {
        return $this->belongsTo(DrawerSession::class);
    }

    public function referenceLabel(): string
    {
        return $this->number;
    }

    public function referenceUrl(): ?string
    {
        return route('sales.returns.show', $this);
    }
}
