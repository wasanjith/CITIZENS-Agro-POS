<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Policies\PackOpeningPolicy;
use App\Domain\Inventory\Support\StockReference;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sealed packs opened into a loose product: Urea 50kg bag × 3 → Urea (loose) + 150 kg.
 * Done when new stock arrives (from the GRN) or later when the loose stock runs out.
 *
 * @property int $id
 * @property string $number
 * @property int $sealed_product_id
 * @property string $packs
 * @property int $loose_product_id
 * @property string $expected_qty
 * @property string $weighed_qty
 * @property string $cost_total
 * @property int|null $goods_receipt_id
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable([
    'number', 'sealed_product_id', 'packs', 'loose_product_id', 'expected_qty', 'weighed_qty',
    'cost_total', 'goods_receipt_id', 'note', 'created_by',
])]
#[UsePolicy(PackOpeningPolicy::class)]
class PackOpening extends Model implements StockReference
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sealed_product_id' => 'integer',
            'packs' => 'decimal:3',
            'loose_product_id' => 'integer',
            'expected_qty' => 'decimal:3',
            'weighed_qty' => 'decimal:3',
            'cost_total' => 'decimal:2',
            'goods_receipt_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function referenceLabel(): string
    {
        return $this->number;
    }

    public function referenceUrl(): ?string
    {
        return route('inventory.pack-openings.show', $this);
    }

    /**
     * Weighed minus expected: negative when the packs held less than their nominal weight.
     */
    public function difference(): BigDecimal
    {
        return BigDecimal::of($this->weighed_qty)->minus($this->expected_qty);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function sealedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sealed_product_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function looseProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'loose_product_id')->withTrashed();
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
