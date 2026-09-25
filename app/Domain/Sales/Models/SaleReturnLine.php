<?php

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One returned invoice line. qty is in the sale unit, base_qty in the base unit.
 * restock = false: damaged goods, written off as DAMAGE straight after coming back.
 *
 * @property int $id
 * @property int $sale_return_id
 * @property int $sale_item_id
 * @property string $qty
 * @property string $base_qty
 * @property string $amount
 * @property bool $restock
 * @property string $cost_total
 */
#[Fillable(['sale_return_id', 'sale_item_id', 'qty', 'base_qty', 'amount', 'restock', 'cost_total'])]
class SaleReturnLine extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_return_id' => 'integer',
            'sale_item_id' => 'integer',
            'qty' => 'decimal:3',
            'base_qty' => 'decimal:3',
            'amount' => 'decimal:2',
            'restock' => 'boolean',
            'cost_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SaleReturn, $this>
     */
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    /**
     * @return BelongsTo<SaleItem, $this>
     */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    /**
     * @return HasMany<SaleReturnLineBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(SaleReturnLineBatch::class);
    }
}
