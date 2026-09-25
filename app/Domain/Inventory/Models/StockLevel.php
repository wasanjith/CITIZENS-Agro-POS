<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cached stock balance of one batch. Only StockService writes it, always in the same
 * transaction as the stock_movements row, so qty_on_hand = SUM(movements.qty).
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $batch_id
 * @property string $qty_on_hand
 * @property string $qty_reserved
 * @property Carbon|null $updated_at
 */
#[Fillable(['product_id', 'variant_id', 'batch_id', 'qty_on_hand', 'qty_reserved'])]
class StockLevel extends Model
{
    public const CREATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'batch_id' => 'integer',
            'qty_on_hand' => 'decimal:3',
            'qty_reserved' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
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
}
