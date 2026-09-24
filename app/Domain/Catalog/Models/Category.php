<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Policies\CategoryPolicy;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Product category. Categories form a tree (Bicycle Parts → Tyres) and may own a short code range.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $name_si
 * @property int|null $code_from
 * @property int|null $code_to
 * @property int $sort_order
 * @property bool $is_active
 */
#[Fillable(['parent_id', 'name', 'name_si', 'code_from', 'code_to', 'sort_order', 'is_active'])]
#[UseFactory(CategoryFactory::class)]
#[UsePolicy(CategoryPolicy::class)]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'code_from' => 'integer',
            'code_to' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['parent_id', 'name', 'name_si', 'code_from', 'code_to', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The categories from the root down to this one.
     *
     * @return list<Category>
     */
    public function ancestry(): array
    {
        $chain = [$this];
        $current = $this;

        // Depth is capped to guard against a cycle created by bad data.
        for ($depth = 0; $depth < 10 && $current->parent_id !== null; $depth++) {
            $current = $current->parent;

            if ($current === null) {
                break;
            }

            array_unshift($chain, $current);
        }

        return $chain;
    }

    /**
     * "Bicycle Parts › Tyres".
     */
    public function path(): string
    {
        return implode(' › ', array_map(fn (Category $category) => $category->name, $this->ancestry()));
    }

    /**
     * The nearest short code range: this category's own, or inherited from a parent.
     *
     * @return array{0: int, 1: int}|null
     */
    public function codeRange(): ?array
    {
        foreach (array_reverse($this->ancestry()) as $category) {
            if ($category->code_from !== null && $category->code_to !== null) {
                return [$category->code_from, $category->code_to];
            }
        }

        return null;
    }

    /**
     * IDs of this category and all categories below it.
     *
     * @return list<int>
     */
    public function descendantIdsAndSelf(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        for ($depth = 0; $depth < 10 && $frontier !== []; $depth++) {
            $frontier = Category::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
