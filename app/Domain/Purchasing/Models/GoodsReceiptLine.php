<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Inventory\Models\Batch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $goods_receipt_id
 * @property int|null $po_line_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $unit_id
 * @property string $qty paid quantity in unit_id
 * @property string $base_qty paid quantity in base units
 * @property string $unit_cost per unit_id
 * @property string|null $lot_no
 * @property Carbon|null $mfg_date
 * @property Carbon|null $expiry_date
 * @property string $free_qty in unit_id
 * @property string $line_total
 * @property int|null $batch_id
 */
#[Fillable([
    'goods_receipt_id', 'po_line_id', 'product_id', 'variant_id', 'unit_id', 'qty', 'base_qty', 'unit_cost',
    'lot_no', 'mfg_date', 'expiry_date', 'free_qty', 'line_total', 'batch_id',
])]
class GoodsReceiptLine extends Model
{
    protected $table = 'grn_lines';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'goods_receipt_id' => 'integer',
            'po_line_id' => 'integer',
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'unit_id' => 'integer',
            'qty' => 'decimal:3',
            'base_qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'mfg_date' => 'date',
            'expiry_date' => 'date',
            'free_qty' => 'decimal:3',
            'line_total' => 'decimal:2',
            'batch_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'po_line_id');
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

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
