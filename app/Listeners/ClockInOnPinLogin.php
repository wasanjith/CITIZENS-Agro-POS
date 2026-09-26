<?php

namespace App\Listeners;

use App\Domain\HR\Models\Employee;
use App\Domain\HR\Services\AttendanceService;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * The first PIN sign-in of the day on a shop terminal clocks the employee in
 * (with the webcam snapshot from the PIN screen when that is switched on).
 */
class ClockInOnPinLogin
{
    public function __construct(
        private readonly CurrentTerminal $currentTerminal,
        private readonly AttendanceService $attendance,
    ) {}

    public function handle(Login $event): void
    {
        $terminal = $this->currentTerminal->get();

        if (! $event->user instanceof User || $terminal === null || ! request()->routeIs('pin-login.store')) {
            return;
        }

        $employee = Employee::query()->active()->where('user_id', $event->user->id)->first();

        if ($employee === null || ! $event->user->can('hr.attendance.self')) {
            return;
        }

        $photo = request()->input('photo');

        $this->attendance->clockIn($employee, $terminal, is_string($photo) ? $photo : null);
    }
}
