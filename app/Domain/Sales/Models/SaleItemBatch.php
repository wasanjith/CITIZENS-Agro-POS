<?php

namespace App\Domain\Sales\Models;

use App\Domain\Inventory\Models\Batch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock a settled sale line was issued from (FEFO), with the batch cost per base unit.
 *
 * @property int $id
 * @property int $sale_item_id
 * @property int $batch_id
 * @property string $base_qty
 * @property string $unit_cost
 */
#[Fillable(['sale_item_id', 'batch_id', 'base_qty', 'unit_cost'])]
class SaleItemBatch extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_item_id' => 'integer',
            'batch_id' => 'integer',
            'base_qty' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<SaleItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
