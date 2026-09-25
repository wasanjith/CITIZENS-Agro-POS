<?php

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $quotation_id
 * @property int $line_no
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $unit_id
 * @property string $qty
 * @property string $factor
 * @property string $base_qty
 * @property string $unit_price
 * @property string $discount_amount
 * @property string $line_total
 * @property string $short_code_snapshot
 * @property string $name_snapshot
 * @property string|null $name_si_snapshot
 * @property string $unit_snapshot
 * @property string|null $unit_si_snapshot
 */
#[Fillable([
    'quotation_id', 'line_no', 'product_id', 'variant_id', 'unit_id', 'qty', 'factor', 'base_qty',
    'unit_price', 'discount_amount', 'line_total',
    'short_code_snapshot', 'name_snapshot', 'name_si_snapshot', 'unit_snapshot', 'unit_si_snapshot',
])]
class QuotationLine extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quotation_id' => 'integer',
            'line_no' => 'integer',
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'unit_id' => 'integer',
            'qty' => 'decimal:3',
            'factor' => 'decimal:3',
            'base_qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
