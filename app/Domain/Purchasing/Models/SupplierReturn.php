<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Inventory\Support\StockReference;
use App\Domain\Purchasing\Policies\SupplierReturnPolicy;
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
 * Goods sent back to a supplier from specific batches. Posted when saved: stock goes
 * out and the supplier is debited.
 *
 * @property int $id
 * @property string $number
 * @property int $supplier_id
 * @property int|null $goods_receipt_id
 * @property Carbon $return_date
 * @property string|null $reason
 * @property string $total
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable(['number', 'supplier_id', 'goods_receipt_id', 'return_date', 'reason', 'total', 'created_by'])]
#[UsePolicy(SupplierReturnPolicy::class)]
class SupplierReturn extends Model implements StockReference
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'goods_receipt_id' => 'integer',
            'return_date' => 'date',
            'total' => 'decimal:2',
            'created_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'supplier_id', 'total', 'reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function referenceLabel(): string
    {
        return $this->number;
    }

    public function referenceUrl(): ?string
    {
        return route('purchasing.supplier-returns.show', $this);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /**
     * @return HasMany<SupplierReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturnLine::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
