<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Unit;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $unit_id
 * @property string $qty in unit_id
 * @property string $base_qty
 * @property string|null $unit_cost per unit_id
 * @property string $received_base_qty
 * @property string $line_total
 */
#[Fillable(['purchase_order_id', 'product_id', 'variant_id', 'unit_id', 'qty', 'base_qty', 'unit_cost', 'received_base_qty', 'line_total'])]
class PurchaseOrderLine extends Model
{
    protected $table = 'po_lines';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchase_order_id' => 'integer',
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'unit_id' => 'integer',
            'qty' => 'decimal:3',
            'base_qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'received_base_qty' => 'decimal:3',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * Base units still to be delivered (never below zero).
     */
    public function outstandingBaseQty(): BigDecimal
    {
        $outstanding = BigDecimal::of($this->base_qty)->minus($this->received_base_qty);

        return $outstanding->isNegative() ? BigDecimal::zero() : $outstanding;
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
