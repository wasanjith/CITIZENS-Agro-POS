<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Policies\PurchaseOrderPolicy;
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
 * Any staff member can raise one (quantities only); a Manager or the Owner fills in
 * the costs and approves it.
 *
 * @property int $id
 * @property string $number
 * @property int $supplier_id
 * @property PurchaseOrderStatus $status
 * @property Carbon $order_date
 * @property Carbon|null $expected_date
 * @property string $subtotal
 * @property string $discount
 * @property string $tax
 * @property string $total
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $submitted_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejected_reason
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 */
#[Fillable([
    'number', 'supplier_id', 'status', 'order_date', 'expected_date', 'subtotal', 'discount', 'tax', 'total', 'note',
    'created_by', 'submitted_at', 'approved_by', 'approved_at', 'rejected_reason', 'sent_at',
])]
#[UsePolicy(PurchaseOrderPolicy::class)]
class PurchaseOrder extends Model
{
    use LogsActivity;

    /**
     * Fields that reveal buying costs (never shown to users without catalog.cost.view).
     */
    public const COST_FIELDS = ['subtotal', 'discount', 'tax', 'total'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'created_by' => 'integer',
            'submitted_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'supplier_id', 'status', 'expected_date', 'rejected_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * subtotal = Σ line totals; total = subtotal − discount + tax. Saves the order.
     */
    public function recalculateTotals(): void
    {
        $subtotal = $this->lines()->get()->reduce(
            fn (BigDecimal $sum, PurchaseOrderLine $line) => $sum->plus($line->line_total),
            BigDecimal::of('0.00'),
        );

        $this->subtotal = (string) $subtotal;
        $this->total = (string) $subtotal->minus($this->discount ?? '0')->plus($this->tax ?? '0')->toScale(2);
        $this->save();
    }

    /**
     * Every line has a unit cost (needed before approval).
     */
    public function isFullyCosted(): bool
    {
        return $this->lines()->whereNull('unit_cost')->doesntExist();
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<GoodsReceipt, $this>
     */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
