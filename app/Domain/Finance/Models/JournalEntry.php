<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Policies\AccountPolicy;
use App\Domain\Inventory\Support\StockReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One balanced journal entry (Σ debit = Σ credit). Never edited; undone by a reversal.
 *
 * @property int $id
 * @property string $number
 * @property Carbon $date
 * @property string $description
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $event
 * @property int|null $reverses_id
 * @property int|null $created_by
 * @property Carbon $created_at
 */
#[Fillable(['number', 'date', 'description', 'source_type', 'source_id', 'event', 'reverses_id', 'created_by'])]
#[UsePolicy(AccountPolicy::class)]
class JournalEntry extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'source_id' => 'integer',
            'reverses_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /**
     * Label and page of the document that posted this entry.
     *
     * @return array{label: string, url: string|null}|null
     */
    public function sourceLink(): ?array
    {
        $source = $this->source;

        if (! $source instanceof StockReference) {
            return null;
        }

        return ['label' => $source->referenceLabel(), 'url' => $source->referenceUrl()];
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reverses_id');
    }

    /**
     * @return HasOne<JournalEntry, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'reverses_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
