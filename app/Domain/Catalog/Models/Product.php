<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Policies\ProductPolicy;
use App\Models\User;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A sellable item. Quantities are always stored in the base unit; product_units
 * holds the other units it is bought or sold in (1 Bag = 50 kg).
 *
 * @property int $id
 * @property string $short_code
 * @property string|null $sku
 * @property string $name
 * @property string|null $name_si
 * @property string|null $name_ta
 * @property string|null $aliases
 * @property string|null $description
 * @property int $category_id
 * @property int|null $brand_id
 * @property int $base_unit_id
 * @property int|null $tax_id
 * @property bool $has_variants
 * @property bool $track_batches
 * @property bool $track_expiry
 * @property string $reorder_level
 * @property string $reorder_qty
 * @property string|null $min_selling_margin_pct
 * @property string|null $reference_cost
 * @property array<string, string>|null $attributes
 * @property string|null $image_path
 * @property bool $is_active
 * @property string $sales_velocity_30d
 * @property int|null $created_by
 */
#[Fillable([
    'short_code', 'sku', 'name', 'name_si', 'name_ta', 'aliases', 'description',
    'category_id', 'brand_id', 'base_unit_id', 'tax_id',
    'has_variants', 'track_batches', 'track_expiry', 'reorder_level', 'reorder_qty',
    'min_selling_margin_pct', 'reference_cost', 'attributes', 'image_path', 'is_active', 'created_by',
])]
#[UseFactory(ProductFactory::class)]
#[UsePolicy(ProductPolicy::class)]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, LogsActivity, Searchable, SoftDeletes;

    /**
     * Fields that reveal the shop's buying cost. Never sent to users without catalog.cost.view.
     */
    public const COST_FIELDS = ['reference_cost', 'min_selling_margin_pct'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'brand_id' => 'integer',
            'base_unit_id' => 'integer',
            'tax_id' => 'integer',
            'has_variants' => 'boolean',
            'track_batches' => 'boolean',
            'track_expiry' => 'boolean',
            'reorder_level' => 'decimal:3',
            'reorder_qty' => 'decimal:3',
            'min_selling_margin_pct' => 'decimal:2',
            'reference_cost' => 'decimal:4',
            'attributes' => 'array',
            'is_active' => 'boolean',
            'sales_velocity_30d' => 'decimal:3',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'short_code', 'sku', 'name', 'name_si', 'aliases', 'category_id', 'brand_id', 'base_unit_id', 'tax_id',
                'has_variants', 'track_batches', 'track_expiry', 'reorder_level', 'reorder_qty',
                'min_selling_margin_pct', 'reference_cost', 'is_active',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('short_code');
    }

    /**
     * @return HasMany<ProductUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('factor');
    }

    /**
     * @return HasMany<ProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * @return HasMany<OpeningStockEntry, $this>
     */
    public function openingStock(): HasMany
    {
        return $this->hasMany(OpeningStockEntry::class);
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function defaultSaleUnit(): ?ProductUnit
    {
        return $this->units->firstWhere('is_default_sale', true)
            ?? $this->units->firstWhere('unit_id', $this->base_unit_id);
    }

    /**
     * Aliases as a clean list ("yuriya, u50" → ['yuriya', 'u50']).
     *
     * @return list<string>
     */
    public function aliasList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', (string) $this->aliases) ?: [])));
    }

    /**
     * The document sent to Meilisearch. Search results are hydrated from MySQL,
     * so live stock and prices never come from the index.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['category.parent.parent', 'brand', 'variants']);

        $attributes = collect($this->attributes ?? [])
            ->map(fn ($value, $key) => "{$key} {$value}")
            ->values()
            ->all();

        return [
            'id' => $this->id,
            'short_code' => $this->short_code,
            'name' => $this->name,
            'aliases' => $this->aliasList(),
            'name_si' => $this->name_si,
            'name_ta' => $this->name_ta,
            'brand' => $this->brand?->name,
            'attributes' => $attributes,
            'variant_codes' => $this->variants->pluck('short_code')->all(),
            'variant_names' => $this->variants->pluck('name')->all(),
            'category' => $this->category?->name,
            'category_path' => $this->category?->path(),
            'category_id' => $this->category_id,
            'category_ids' => array_map(fn (Category $category) => $category->id, $this->category?->ancestry() ?? []),
            'brand_id' => $this->brand_id,
            'is_active' => $this->is_active,
            'sales_velocity_30d' => (float) $this->sales_velocity_30d,
        ];
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with(['category.parent.parent', 'brand', 'variants']);
    }
}
