<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Database\Factories\BatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A lot of one product/variant with its own cost (per base unit) and expiry.
 * Products without batch tracking have a single default batch (default_key set)
 * whose cost is a moving average.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property string|null $lot_no
 * @property Carbon|null $mfg_date
 * @property Carbon|null $expiry_date
 * @property string $unit_cost
 * @property Carbon $received_at
 * @property int|null $grn_line_id
 * @property string|null $default_key
 */
#[Fillable(['product_id', 'variant_id', 'lot_no', 'mfg_date', 'expiry_date', 'unit_cost', 'received_at', 'grn_line_id', 'default_key'])]
#[UseFactory(BatchFactory::class)]
class Batch extends Model
{
    /** @use HasFactory<BatchFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'mfg_date' => 'date',
            'expiry_date' => 'date',
            'unit_cost' => 'decimal:4',
            'received_at' => 'datetime',
            'grn_line_id' => 'integer',
        ];
    }

    public static function defaultKey(int $productId, ?int $variantId): string
    {
        return $productId.'-'.($variantId ?? 0);
    }

    public function isDefault(): bool
    {
        return $this->default_key !== null;
    }

    /**
     * Short label for pickers and tables: "Lot A12 · exp 2027-01-31".
     */
    public function label(): string
    {
        $parts = [];

        if ($this->isDefault()) {
            $parts[] = 'General stock';
        } elseif ($this->lot_no) {
            $parts[] = "Lot {$this->lot_no}";
        } else {
            $parts[] = 'Received '.$this->received_at->format('Y-m-d');
        }

        if ($this->expiry_date) {
            $parts[] = 'exp '.$this->expiry_date->format('Y-m-d');
        }

        return implode(' · ', $parts);
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
     * @return HasOne<StockLevel, $this>
     */
    public function level(): HasOne
    {
        return $this->hasOne(StockLevel::class);
    }
}
