<?php

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Inventory\Data\StockAllocation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a sale. qty is in the sale unit, base_qty in the product's base unit.
 * Names are snapshots, so the printed invoice never changes when the product is renamed.
 *
 * @property int $id
 * @property int $sale_id
 * @property int $line_no
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $unit_id
 * @property string $qty
 * @property string $factor
 * @property string $base_qty
 * @property string $unit_price
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $line_total
 * @property string|null $cost_total
 * @property string $short_code_snapshot
 * @property string $name_snapshot
 * @property string|null $name_si_snapshot
 * @property string $unit_snapshot
 * @property string|null $unit_si_snapshot
 * @property list<array{batch_id: int, qty: string, unit_cost: string}>|null $reservations
 * @property int|null $approval_request_id
 */
#[Fillable([
    'sale_id', 'line_no', 'product_id', 'variant_id', 'unit_id', 'qty', 'factor', 'base_qty',
    'unit_price', 'discount_amount', 'tax_amount', 'line_total',
    'short_code_snapshot', 'name_snapshot', 'name_si_snapshot', 'unit_snapshot', 'unit_si_snapshot',
    'reservations', 'approval_request_id',
])]
class SaleItem extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_id' => 'integer',
            'line_no' => 'integer',
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'unit_id' => 'integer',
            'qty' => 'decimal:3',
            'factor' => 'decimal:3',
            'base_qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'reservations' => 'array',
            'approval_request_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
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
     * @return HasMany<SaleItemBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(SaleItemBatch::class);
    }

    /**
     * @return list<StockAllocation>
     */
    public function reservationAllocations(): array
    {
        return array_map(
            fn (array $row) => new StockAllocation((int) $row['batch_id'], (string) $row['qty'], (string) $row['unit_cost']),
            $this->reservations ?? [],
        );
    }

    /**
     * @param  list<StockAllocation>  $allocations
     * @return list<array{batch_id: int, qty: string, unit_cost: string}>
     */
    public static function reservationsFrom(array $allocations): array
    {
        return array_map(
            fn (StockAllocation $allocation) => ['batch_id' => $allocation->batchId, 'qty' => $allocation->qty, 'unit_cost' => $allocation->unitCost],
            $allocations,
        );
    }
}
