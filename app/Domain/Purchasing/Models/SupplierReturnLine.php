<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\Batch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $supplier_return_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $batch_id
 * @property string $qty base units
 * @property string $unit_cost per base unit
 * @property string $line_total
 */
#[Fillable(['supplier_return_id', 'product_id', 'variant_id', 'batch_id', 'qty', 'unit_cost', 'line_total'])]
class SupplierReturnLine extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_return_id' => 'integer',
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'batch_id' => 'integer',
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:2',
        ];
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
}
