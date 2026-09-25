<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\MovementType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of the append-only stock ledger. qty is signed and in the product's base unit.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $batch_id
 * @property MovementType $type
 * @property string $qty
 * @property string $unit_cost
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $user_id
 * @property string|null $note
 * @property Carbon $created_at
 */
#[Fillable(['product_id', 'variant_id', 'batch_id', 'type', 'qty', 'unit_cost', 'reference_type', 'reference_id', 'user_id', 'note', 'created_at'])]
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements are append-only.'));
        static::deleting(fn () => throw new LogicException('Stock movements are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'batch_id' => 'integer',
            'type' => MovementType::class,
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'reference_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
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
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
