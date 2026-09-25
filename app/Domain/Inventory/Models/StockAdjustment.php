<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\AdjustmentReason;
use App\Domain\Inventory\Enums\AdjustmentStatus;
use App\Domain\Inventory\Policies\StockAdjustmentPolicy;
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
 * A manual stock correction (damage, expiry, loss, found stock). Above the approval
 * limit it waits for the Super Admin before stock changes.
 *
 * @property int $id
 * @property string $number
 * @property AdjustmentReason $reason
 * @property AdjustmentStatus $status
 * @property string $total_value
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejection_reason
 * @property string|null $note
 * @property Carbon|null $created_at
 */
#[Fillable(['number', 'reason', 'status', 'total_value', 'created_by', 'approved_by', 'approved_at', 'rejection_reason', 'note'])]
#[UsePolicy(StockAdjustmentPolicy::class)]
class StockAdjustment extends Model implements StockReference
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => AdjustmentReason::class,
            'status' => AdjustmentStatus::class,
            'total_value' => 'decimal:2',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'reason', 'status', 'total_value', 'rejection_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function referenceLabel(): string
    {
        return $this->number;
    }

    public function referenceUrl(): ?string
    {
        return route('inventory.adjustments.show', $this);
    }

    /**
     * @return HasMany<StockAdjustmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'adjustment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
