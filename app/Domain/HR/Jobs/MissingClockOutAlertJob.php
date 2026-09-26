<?php

namespace App\Domain\HR\Jobs;

use App\Domain\HR\Models\Attendance;
use App\Domain\HR\Notifications\MissingClockOutAlert;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Every morning: tells whoever corrects attendance (bell notification) about staff who
 * clocked in yesterday but never clocked out, so the time can be entered by hand.
 */
class MissingClockOutAlertJob implements ShouldQueue
{
    use Queueable;

    public function handle(Settings $settings): void
    {
        if (! (bool) $settings->get('hr.missing_clock_out_alert', true)) {
            return;
        }

        $yesterday = today()->subDay();

        $names = Attendance::query()
            ->whereDate('date', $yesterday->toDateString())
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->with('employee:id,full_name')
            ->get()
            ->map(fn (Attendance $attendance) => $attendance->employee->full_name)
            ->sort()
            ->values()
            ->all();

        if ($names === []) {
            return;
        }

        Notification::send(
            User::permission('hr.attendance.edit')->active()->get(),
            new MissingClockOutAlert($yesterday->toDateString(), $names),
        );
    }
}
