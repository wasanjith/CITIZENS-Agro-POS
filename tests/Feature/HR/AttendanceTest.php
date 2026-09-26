<?php

use App\Domain\HR\Enums\AttendanceStatus;
use App\Domain\HR\Jobs\MissingClockOutAlertJob;
use App\Domain\HR\Models\Attendance;
use App\Domain\HR\Models\Holiday;
use App\Domain\HR\Notifications\MissingClockOutAlert;
use App\Domain\HR\Services\AttendanceService;
use App\Domain\System\Services\Settings;
use Database\Seeders\HrSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    // Monday 28 September 2026. Default shift: 08:00â€“18:00, late after 10 minutes, Monâ€“Sat.
    $this->travelTo(Carbon::parse('2026-09-28 08:25:00'));
    $this->pos = posSetup();
    $this->seed(HrSeeder::class);
    $this->nimal = employee(['full_name' => 'Nimal Bandara'], $this->pos['staff']);
});

function pinSignIn(array $pos, string $pin = '3331', array $extra = []): void
{
    test()->withCookie(deviceCookie(), $pos['counterToken'])
        ->post(route('pin-login.store'), ['user_id' => $pos['staff']->id, 'pin' => $pin, ...$extra])
        ->assertRedirect();
}

test('the first PIN sign-in of the day clocks the employee in; later sign-ins change nothing', function () {
    pinSignIn($this->pos);

    $row = Attendance::sole();
    expect($row->clock_in->format('H:i'))->toBe('08:25')
        ->and($row->status)->toBe(AttendanceStatus::Present)
        ->and($row->terminal_id)->toBe($this->pos['counter']->id)
        ->and($row->late_minutes)->toBe(25);

    auth()->logout();
    $this->travelTo(Carbon::parse('2026-09-28 13:00:00'));
    pinSignIn($this->pos);

    expect(Attendance::count())->toBe(1)
        ->and(Attendance::sole()->clock_in->format('H:i'))->toBe('08:25');
});

test('a password sign-in does not clock in, and users who are not employees are ignored', function () {
    $this->post(route('login'), ['username' => $this->pos['staff']->username, 'password' => 'password'])->assertRedirect();

    $this->withCookie(deviceCookie(), $this->pos['counterToken'])
        ->post(route('pin-login.store'), ['user_id' => $this->pos['manager']->id, 'pin' => '2222']);

    expect(Attendance::count())->toBe(0);
});

test('coming in within the grace minutes is not late', function () {
    $this->travelTo(Carbon::parse('2026-09-28 08:09:00'));

    expect(app(AttendanceService::class)->clockIn($this->nimal)->late_minutes)->toBe(0);
});

test('leaving early, a little overtime below the minimum, and real overtime', function (string $out, int $early, int $overtime) {
    $this->travelTo(Carbon::parse('2026-09-28 07:55:00'));
    app(AttendanceService::class)->clockIn($this->nimal);

    $this->travelTo(Carbon::parse("2026-09-28 {$out}:00"));
    $row = app(AttendanceService::class)->clockOut($this->nimal);

    expect($row->early_leave_minutes)->toBe($early)
        ->and($row->ot_minutes)->toBe($overtime)
        ->and($row->late_minutes)->toBe(0);
})->with([
    'left at 17:30' => ['17:30', 30, 0],
    '20 minutes over (below the 30-minute minimum)' => ['18:20', 0, 0],
    '45 minutes over' => ['18:45', 0, 45],
]);

test('every minute worked on a day off or a holiday is overtime', function () {
    $service = app(AttendanceService::class);

    // Sunday 27 September.
    $this->travelTo(Carbon::parse('2026-09-27 09:00:00'));
    $service->clockIn($this->nimal);
    $this->travelTo(Carbon::parse('2026-09-27 13:00:00'));
    expect($service->clockOut($this->nimal)->ot_minutes)->toBe(240);

    Holiday::create(['date' => '2026-09-29', 'name' => 'Test Poya']);
    $this->travelTo(Carbon::parse('2026-09-29 10:00:00'));
    $service->clockIn($this->nimal);
    $this->travelTo(Carbon::parse('2026-09-29 12:00:00'));
    $row = $service->clockOut($this->nimal);

    expect($row->ot_minutes)->toBe(120)->and($row->late_minutes)->toBe(0);
});

test('staff clock out from the top bar; clocking out twice is refused', function () {
    pinSignIn($this->pos);
    $this->travelTo(Carbon::parse('2026-09-28 18:05:00'));

    atTerminal($this->pos['counterToken'], $this->pos['staff'])->post(route('hr.clock-out'))->assertSessionHasNoErrors();
    expect(Attendance::sole()->clock_out->format('H:i'))->toBe('18:05');

    atTerminal($this->pos['counterToken'], $this->pos['staff'])->post(route('hr.clock-out'))->assertSessionHasErrors('attendance');
});

test('clocking in by hand needs a shop terminal', function () {
    $this->actingAs($this->pos['staff'])->post(route('hr.clock-in'))->assertSessionHasErrors('attendance');
    atTerminal($this->pos['counterToken'], $this->pos['staff'])->post(route('hr.clock-in'))->assertSessionHasNoErrors();

    expect(Attendance::count())->toBe(1);
});

test('the clock-in photo is kept only when switched on', function () {
    Storage::fake('local');
    $jpeg = 'data:image/jpeg;base64,'.base64_encode(jpegBytes());

    pinSignIn($this->pos, extra: ['photo' => $jpeg]);
    expect(Attendance::sole()->photo_path)->toBeNull();

    Attendance::query()->delete();
    auth()->logout();
    app(Settings::class)->setGroup('hr', ['clock_in_photo' => true]);
    pinSignIn($this->pos, extra: ['photo' => $jpeg]);

    $row = Attendance::sole();
    expect($row->photo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($row->photo_path);
    $this->actingAs($this->pos['owner'])->get(route('hr.attendance.photo', $row))->assertOk();
});

test('the owner corrects a day with a reason; the Manager only looks; staff see only their own', function () {
    $this->actingAs($this->pos['owner'])->put(route('hr.attendance.update'), [
        'employee_id' => $this->nimal->id,
        'date' => '2026-09-26',
        'status' => 'present',
        'clock_in' => '08:30',
        'clock_out' => '19:00',
        'edit_reason' => 'Forgot to sign in',
    ])->assertSessionHasNoErrors();

    $row = Attendance::sole();
    expect($row->late_minutes)->toBe(30)
        ->and($row->ot_minutes)->toBe(60)
        ->and($row->edited_by)->toBe($this->pos['owner']->id)
        ->and(Activity::where('subject_type', $row->getMorphClass())->where('subject_id', $row->id)->exists())->toBeTrue();

    $this->actingAs($this->pos['owner'])->put(route('hr.attendance.update'), [
        'employee_id' => $this->nimal->id, 'date' => '2026-09-26', 'status' => 'present', 'clock_in' => '08:30', 'edit_reason' => '',
    ])->assertSessionHasErrors('edit_reason');

    $this->actingAs($this->pos['manager'])->get(route('hr.attendance.index', ['date' => '2026-09-26']))->assertOk()->assertSee('Forgot to sign in');
    $this->actingAs($this->pos['manager'])->get(route('hr.attendance.month'))->assertOk();
    $this->actingAs($this->pos['manager'])->put(route('hr.attendance.update'), [
        'employee_id' => $this->nimal->id, 'date' => '2026-09-26', 'status' => 'absent', 'edit_reason' => 'x',
    ])->assertForbidden();

    $this->actingAs($this->pos['staff'])->get(route('hr.attendance.index'))->assertForbidden();
    $this->actingAs($this->pos['staff'])->get(route('hr.attendance.mine', ['month' => '2026-09']))->assertOk()->assertSee('08:30');
});

test('attendance cannot be entered for a future day', function () {
    $this->actingAs($this->pos['owner'])->put(route('hr.attendance.update'), [
        'employee_id' => $this->nimal->id, 'date' => '2026-09-30', 'status' => 'absent', 'edit_reason' => 'Planned',
    ])->assertSessionHasErrors('date');
});

test('the owner is told in the morning who did not clock out yesterday', function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-09-28 08:00:00'));
    app(AttendanceService::class)->clockIn($this->nimal);

    $this->travelTo(Carbon::parse('2026-09-29 07:15:00'));
    (new MissingClockOutAlertJob)->handle(app(Settings::class));

    Notification::assertSentTo($this->pos['owner'], MissingClockOutAlert::class, fn ($alert) => $alert->names === ['Nimal Bandara'] && $alert->date === '2026-09-28');
    Notification::assertNotSentTo($this->pos['manager'], MissingClockOutAlert::class);
    expect(Attendance::sole()->missingClockOut())->toBeTrue();
});

function jpegBytes(): string
{
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}
