<?php

namespace App\Domain\HR\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case HalfDay = 'half_day';
    case Absent = 'absent';
    case Leave = 'leave';
    case Holiday = 'holiday';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::HalfDay => 'Half day',
            self::Absent => 'Absent',
            self::Leave => 'Leave',
            self::Holiday => 'Holiday',
        };
    }

    /**
     * One letter for the monthly grid.
     */
    public function letter(): string
    {
        return match ($this) {
            self::Present => 'P',
            self::HalfDay => '½',
            self::Absent => 'A',
            self::Leave => 'L',
            self::Holiday => 'H',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Present => 'green',
            self::HalfDay => 'amber',
            self::Absent => 'red',
            self::Leave => 'blue',
            self::Holiday => 'purple',
        };
    }

    /**
     * Statuses that have clock-in / clock-out times.
     */
    public function hasTimes(): bool
    {
        return $this === self::Present || $this === self::HalfDay;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [$status->value => $status->label()])->all();
    }
}
