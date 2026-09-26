<?php

namespace App\Domain\HR\Models;

use App\Domain\HR\Policies\EmployeePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $days_per_year 0 = no limit
 * @property bool $is_paid
 * @property bool $is_active
 */
#[Fillable(['name', 'days_per_year', 'is_paid', 'is_active'])]
#[UsePolicy(EmployeePolicy::class)]
class LeaveType extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days_per_year' => 'decimal:1',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<LeaveType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function hasLimit(): bool
    {
        return (float) $this->days_per_year > 0;
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return self::query()->active()->orderBy('id')->pluck('name', 'id')->all();
    }
}
