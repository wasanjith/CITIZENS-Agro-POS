<?php

namespace App\Domain\HR\Models;

use App\Domain\HR\Policies\EmployeePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Working hours used for late / early leave / overtime. Employees without a shift use
 * the default one.
 *
 * @property int $id
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property int $grace_minutes
 * @property list<int> $working_days
 * @property bool $is_default
 */
#[Fillable(['name', 'start_time', 'end_time', 'grace_minutes', 'working_days', 'is_default'])]
#[UsePolicy(EmployeePolicy::class)]
class Shift extends Model
{
    public const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grace_minutes' => 'integer',
            'working_days' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public static function default(): ?self
    {
        return self::query()->orderByDesc('is_default')->orderBy('id')->first();
    }

    public function worksOn(Carbon $date): bool
    {
        return in_array($date->isoWeekday(), array_map('intval', $this->working_days), true);
    }

    public function startOn(Carbon $date): Carbon
    {
        return $date->copy()->setTimeFromTimeString($this->start_time);
    }

    public function endOn(Carbon $date): Carbon
    {
        return $date->copy()->setTimeFromTimeString($this->end_time);
    }

    public function lengthMinutes(): int
    {
        $today = today();

        return (int) $this->startOn($today)->diffInMinutes($this->endOn($today));
    }

    public function hoursLabel(): string
    {
        return substr($this->start_time, 0, 5).'–'.substr($this->end_time, 0, 5);
    }

    public function daysLabel(): string
    {
        return collect($this->working_days)->sort()->map(fn ($day) => self::DAY_NAMES[(int) $day] ?? '?')->implode(', ');
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return self::query()->orderBy('name')->get()->mapWithKeys(fn (self $shift) => [$shift->id => "{$shift->name} ({$shift->hoursLabel()})"])->all();
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
