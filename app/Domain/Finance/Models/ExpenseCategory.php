<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Policies\ExpensePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What an expense was for (rent, electricity …). Each category posts to its own
 * expense account.
 *
 * @property int $id
 * @property string $name
 * @property int $account_id
 * @property bool $is_active
 */
#[Fillable(['name', 'account_id', 'is_active'])]
#[UsePolicy(ExpensePolicy::class)]
class ExpenseCategory extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<ExpenseCategory>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return self::query()->active()->orderBy('name')->pluck('name', 'id')->all();
    }
}
