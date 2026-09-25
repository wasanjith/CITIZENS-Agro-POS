<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $stocktake_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int|null $batch_id
 * @property string $system_qty
 * @property string|null $counted_qty
 * @property string $unit_cost
 * @property int|null $counted_by
 * @property Carbon|null $counted_at
 */
#[Fillable(['stocktake_id', 'product_id', 'variant_id', 'batch_id', 'system_qty', 'counted_qty', 'unit_cost', 'counted_by', 'counted_at'])]
class StocktakeLine extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stocktake_id' => 'integer',
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'batch_id' => 'integer',
            'system_qty' => 'decimal:3',
            'counted_qty' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'counted_by' => 'integer',
            'counted_at' => 'datetime',
        ];
    }

    /**
     * counted − system, or null while not counted.
     */
    public function variance(): ?BigDecimal
    {
        return $this->counted_qty === null ? null : BigDecimal::of($this->counted_qty)->minus($this->system_qty);
    }

    /**
     * @return BelongsTo<Stocktake, $this>
     */
    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
