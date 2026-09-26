<?php

namespace App\Domain\HR\Models;

use App\Domain\HR\Policies\EmployeePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Poya days and public holidays: not working days, so they are neither absent nor
 * no-pay; work on a holiday is overtime.
 *
 * @property int $id
 * @property Carbon $date
 * @property string $name
 */
#[Fillable(['date', 'name'])]
#[UsePolicy(EmployeePolicy::class)]
class Holiday extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /**
     * @return array<string, string> Y-m-d => name
     */
    public static function between(Carbon $from, Carbon $to): array
    {
        return self::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->mapWithKeys(fn (self $holiday) => [$holiday->date->toDateString() => $holiday->name])
            ->all();
    }
}
