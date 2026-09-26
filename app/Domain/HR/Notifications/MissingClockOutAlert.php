<?php

namespace App\Domain\HR\Notifications;

use App\Domain\System\Notifications\AppNotification;

/**
 * Morning notice: staff who clocked in yesterday and never clocked out.
 */
class MissingClockOutAlert extends AppNotification
{
    /**
     * @param  list<string>  $names
     */
    public function __construct(
        public readonly string $date,
        public readonly array $names,
    ) {}

    public function title(): string
    {
        return 'Missing clock-out';
    }

    public function message(): string
    {
        return implode(', ', $this->names).' did not clock out on '.$this->date.'. Enter the time they left on the attendance sheet.';
    }

    public function url(): string
    {
        return route('hr.attendance.index', ['date' => $this->date]);
    }
}
