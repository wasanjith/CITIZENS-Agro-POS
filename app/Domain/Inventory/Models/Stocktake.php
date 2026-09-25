<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Category;
use App\Domain\Inventory\Enums\StocktakeStatus;
use App\Domain\Inventory\Policies\StocktakePolicy;
use App\Domain\Inventory\Support\StockReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A physical stock count. System quantities are frozen when it starts; posting
 * writes the differences (counted − system) as STOCKTAKE movements.
 *
 * @property int $id
 * @property string $number
 * @property list<int>|null $scope category ids, null = whole shop
 * @property StocktakeStatus $status
 * @property string|null $note
 * @property int|null $started_by
 * @property int|null $posted_by
 * @property Carbon|null $posted_at
 * @property Carbon|null $created_at
 */
#[Fillable(['number', 'scope', 'status', 'note', 'started_by', 'posted_by', 'posted_at'])]
#[UsePolicy(StocktakePolicy::class)]
class Stocktake extends Model implements StockReference
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'status' => StocktakeStatus::class,
            'started_by' => 'integer',
            'posted_by' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function referenceLabel(): string
    {
        return $this->number;
    }

    public function referenceUrl(): ?string
    {
        return route('inventory.stocktakes.show', $this);
    }

    /**
     * "Fertilizers, Seeds" or "Whole shop".
     */
    public function scopeLabel(): string
    {
        if (empty($this->scope)) {
            return 'Whole shop';
        }

        return Category::withTrashed()->whereIn('id', $this->scope)->orderBy('name')->pluck('name')->implode(', ');
    }

    /**
     * @return HasMany<StocktakeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
