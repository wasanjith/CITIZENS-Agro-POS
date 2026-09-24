<?php

namespace App\Domain\Catalog\Models;

use Database\Factories\ProductUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A unit a product is sold or bought in. factor = base units per one of this unit.
 * The base unit itself is always present with factor 1.
 *
 * @property int $id
 * @property int $product_id
 * @property int $unit_id
 * @property string $factor
 * @property bool $is_default_sale
 * @property bool $is_default_purchase
 */
#[Fillable(['product_id', 'unit_id', 'factor', 'is_default_sale', 'is_default_purchase'])]
#[UseFactory(ProductUnitFactory::class)]
class ProductUnit extends Model
{
    /** @use HasFactory<ProductUnitFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'unit_id' => 'integer',
            'factor' => 'decimal:3',
            'is_default_sale' => 'boolean',
            'is_default_purchase' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
