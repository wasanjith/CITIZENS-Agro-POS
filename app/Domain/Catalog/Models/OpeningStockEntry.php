<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Inventory\Support\StockReference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Opening stock read from the product import. Posted as OPENING stock movements
 * once inventory exists (Phase 2); posted_at is set then.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property string $qty
 * @property string|null $unit_cost
 * @property string|null $lot_no
 * @property Carbon|null $expiry_date
 * @property Carbon|null $posted_at
 * @property int|null $created_by
 */
#[Fillable(['product_id', 'variant_id', 'qty', 'unit_cost', 'lot_no', 'expiry_date', 'posted_at', 'created_by'])]
class OpeningStockEntry extends Model implements StockReference
{
    public function referenceLabel(): string
    {
        return 'Opening stock';
    }

    public function referenceUrl(): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'expiry_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
