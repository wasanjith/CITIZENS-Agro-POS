<?php

namespace App\Domain\Catalog\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quick-grid item on the POS screen. user_id null = shop-wide favourite.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $sort_order
 */
#[Fillable(['user_id', 'product_id', 'variant_id', 'sort_order'])]
class FavoriteProduct extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
