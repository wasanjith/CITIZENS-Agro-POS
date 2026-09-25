<?php

namespace App\Domain\Sales\Models;

use App\Domain\Inventory\Models\Batch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The batch a returned quantity went back to, at the cost it was sold from.
 *
 * @property int $id
 * @property int $sale_return_line_id
 * @property int $batch_id
 * @property string $base_qty
 * @property string $unit_cost
 */
#[Fillable(['sale_return_line_id', 'batch_id', 'base_qty', 'unit_cost'])]
class SaleReturnLineBatch extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_return_line_id' => 'integer',
            'batch_id' => 'integer',
            'base_qty' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
