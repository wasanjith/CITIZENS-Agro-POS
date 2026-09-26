<?php

namespace App\Domain\HR\Models;

use App\Domain\HR\Enums\SalaryComponentCalc;
use App\Domain\HR\Enums\SalaryComponentType;
use App\Domain\HR\Policies\PayrollPolicy;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A regular allowance (transport, attendance bonus …) or deduction (welfare society …)
 * given to the employees it is assigned to. An employee can have their own amount.
 *
 * @property int $id
 * @property string $name
 * @property SalaryComponentType $type
 * @property SalaryComponentCalc $calc
 * @property string $value
 * @property bool $is_epf_applicable
 * @property bool $is_active
 */
#[Fillable(['name', 'type', 'calc', 'value', 'is_epf_applicable', 'is_active'])]
#[UsePolicy(PayrollPolicy::class)]
class SalaryComponent extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SalaryComponentType::class,
            'calc' => SalaryComponentCalc::class,
            'value' => 'decimal:2',
            'is_epf_applicable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'type', 'calc', 'value', 'is_epf_applicable', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @param  Builder<SalaryComponent>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The amount for one month: a fixed amount, or a percentage of the basic salary.
     */
    public function amountFor(BigDecimal|string $basic, BigDecimal|string|null $override = null): BigDecimal
    {
        $value = $override !== null ? Money::of($override) : Money::of($this->value);

        return $this->calc === SalaryComponentCalc::PercentBasic
            ? Money::of($basic)->multipliedBy($value)->dividedBy(100, 2, RoundingMode::HalfUp)
            : $value;
    }

    public function valueLabel(): string
    {
        return $this->calc === SalaryComponentCalc::PercentBasic ? rtrim(rtrim((string) $this->value, '0'), '.').'% of basic' : 'Rs. '.Money::format($this->value);
    }

    /**
     * @return BelongsToMany<Employee, $this>
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_salary_components')->withPivot('value_override')->withTimestamps();
    }
}
