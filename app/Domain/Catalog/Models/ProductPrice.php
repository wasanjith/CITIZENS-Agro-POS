<?php

namespace App\Domain\Catalog\Models;

use App\Models\User;
use Database\Factories\ProductPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One price for a product × unit × price list. Rows are never edited: a price
 * change inserts a new row, so the table is also the price history.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $unit_id
 * @property int $price_list_id
 * @property string $price
 * @property Carbon $effective_from
 * @property int|null $created_by
 */
#[Fillable(['product_id', 'variant_id', 'unit_id', 'price_list_id', 'price', 'effective_from', 'created_by'])]
#[UseFactory(ProductPriceFactory::class)]
class ProductPrice extends Model
{
    /** @use HasFactory<ProductPriceFactory> */
    use HasFactory, LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'unit_id' => 'integer',
            'price_list_id' => 'integer',
            'price' => 'decimal:2',
            'effective_from' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['product_id', 'variant_id', 'unit_id', 'price_list_id', 'price', 'effective_from']);
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

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
