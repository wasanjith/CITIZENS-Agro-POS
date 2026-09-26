<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Inventory\Support\StockReference;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Policies\GoodsReceiptPolicy;
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
 * Goods received note. Posting it adds the stock (one batch per line for batch-tracked
 * products) and credits the supplier.
 *
 * @property int $id
 * @property string $number
 * @property int $supplier_id
 * @property int|null $purchase_order_id
 * @property string|null $supplier_invoice_no
 * @property Carbon $received_at
 * @property string $subtotal
 * @property string $amount_paid
 * @property string $discount
 * @property string $tax
 * @property string $total
 * @property GoodsReceiptStatus $status
 * @property string|null $note
 * @property int|null $received_by
 * @property Carbon|null $posted_at
 * @property Carbon|null $created_at
 */
#[Fillable([
    'number', 'supplier_id', 'purchase_order_id', 'supplier_invoice_no', 'received_at',
    'subtotal', 'discount', 'tax', 'total', 'status', 'note', 'received_by', 'posted_at',
])]
#[UsePolicy(GoodsReceiptPolicy::class)]
class GoodsReceipt extends Model implements StockReference
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'purchase_order_id' => 'integer',
            'received_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'status' => GoodsReceiptStatus::class,
            'received_by' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'supplier_id', 'purchase_order_id', 'supplier_invoice_no', 'status', 'total'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * What is still to be paid on this receipt.
     */
    public function outstanding(): BigDecimal
    {
        return BigDecimal::of($this->total)->minus($this->amount_paid)->toScale(2);
    }

    public function referenceLabel(): string
    {
        return $this->number;
    }

    public function referenceUrl(): ?string
    {
        return route('purchasing.goods-receipts.show', $this);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return HasMany<GoodsReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
