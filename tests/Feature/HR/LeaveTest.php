<?php

use App\Domain\HR\Enums\AttendanceStatus;
use App\Domain\HR\Enums\LeaveStatus;
use App\Domain\HR\Models\Attendance;
use App\Domain\HR\Models\Holiday;
use App\Domain\HR\Models\LeaveRequest;
use App\Domain\HR\Models\LeaveType;
use App\Domain\HR\Services\LeaveService;
use App\Domain\Identity\Enums\Role;
use Database\Seeders\HrSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-28 10:00:00'));
    $this->pos = posSetup();
    $this->seed(HrSeeder::class);
    $this->nimal = employee(['full_name' => 'Nimal Bandara'], $this->pos['staff']);
    $this->annual = LeaveType::firstWhere('name', 'Annual leave');
    $this->casual = LeaveType::firstWhere('name', 'Casual leave');
});

test('staff ask for leave; working days only are counted; the owner approves', function () {
    Holiday::create(['date' => '2026-10-02', 'name' => 'Test Poya']);

    // Thursday 1 → Monday 5 October: Thu, (Fri holiday), Sat, (Sun off), Mon = 3 days.
    $this->actingAs($this->pos['staff'])->post(route('hr.leave.request'), [
        'leave_type_id' => $this->annual->id,
        'from_date' => '2026-10-01',
        'to_date' => '2026-10-05',
        'reason' => 'Family wedding',
    ])->assertSessionHasNoErrors();

    $request = LeaveRequest::sole();
    expect($request->status)->toBe(LeaveStatus::Pending)
        ->and((string) $request->days)->toBe('3.0')
        ->and($request->employee_id)->toBe($this->nimal->id);

    $this->actingAs($this->pos['manager'])->post(route('hr.leave.approve', $request))->assertForbidden();
    $this->actingAs($this->pos['owner'])->post(route('hr.leave.approve', $request))->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe(LeaveStatus::Approved)
        ->and(Attendance::orderBy('date')->pluck('date')->map->toDateString()->all())->toBe(['2026-10-01', '2026-10-03', '2026-10-05'])
        ->and(Attendance::first()->status)->toBe(AttendanceStatus::Leave);

    $balance = app(LeaveService::class)->balances($this->nimal, 2026)->firstWhere('type.id', $this->annual->id);
    expect($balance['taken'])->toBe('3.0')->and($balance['remaining'])->toBe('11.0');
});

test('leave beyond what is left for the year is refused at approval', function () {
    $this->actingAs($this->pos['owner'])->post(route('hr.leave.store'), [
        'employee_id' => $this->nimal->id, 'leave_type_id' => $this->casual->id, 'from_date' => '2026-10-05', 'to_date' => '2026-10-10', 'approve' => '1',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->pos['owner'])->post(route('hr.leave.store'), [
        'employee_id' => $this->nimal->id, 'leave_type_id' => $this->casual->id, 'from_date' => '2026-10-12', 'to_date' => '2026-10-13', 'approve' => '1',
    ])->assertSessionHasErrors('leave');

    expect(LeaveRequest::count())->toBe(1)
        ->and(app(LeaveService::class)->balances($this->nimal, 2026)->firstWhere('type.id', $this->casual->id)['remaining'])->toBe('1.0');
});

test('overlapping leave and days off only are refused', function () {
    app(LeaveService::class)->request(['employee_id' => $this->nimal->id, 'leave_type_id' => $this->annual->id, 'from_date' => '2026-10-05', 'to_date' => '2026-10-06'], $this->pos['owner']);

    $this->actingAs($this->pos['staff'])->post(route('hr.leave.request'), ['leave_type_id' => $this->annual->id, 'from_date' => '2026-10-06'])->assertSessionHasErrors('from_date');
    $this->actingAs($this->pos['staff'])->post(route('hr.leave.request'), ['leave_type_id' => $this->annual->id, 'from_date' => '2026-10-04'])->assertSessionHasErrors('from_date');
});

test('half-day leave is half a day; cancelling approved leave removes it from the attendance', function () {
    $this->actingAs($this->pos['owner'])->post(route('hr.leave.store'), [
        'employee_id' => $this->nimal->id, 'leave_type_id' => $this->annual->id, 'from_date' => '2026-10-05', 'half_day' => '1', 'approve' => '1',
    ])->assertSessionHasNoErrors();

    $request = LeaveRequest::sole();
    expect((string) $request->days)->toBe('0.5')->and(Attendance::sole()->status)->toBe(AttendanceStatus::HalfDay);

    $this->actingAs($this->pos['owner'])->post(route('hr.leave.cancel', $request))->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(LeaveStatus::Cancelled)->and(Attendance::count())->toBe(0);
});

test('staff withdraw their own waiting request but not someone else\'s', function () {
    $this->actingAs($this->pos['staff'])->post(route('hr.leave.request'), ['leave_type_id' => $this->annual->id, 'from_date' => '2026-10-05']);
    $request = LeaveRequest::sole();

    $other = userWithRole(Role::SalesStaff);
    $this->actingAs($other)->post(route('hr.leave.cancel', $request))->assertForbidden();
    $this->actingAs($this->pos['staff'])->post(route('hr.leave.cancel', $request))->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe(LeaveStatus::Cancelled);
});

test('the Manager sees the leave list; staff do not', function () {
    $this->actingAs($this->pos['manager'])->get(route('hr.leave.index'))->assertOk();
    $this->actingAs($this->pos['staff'])->get(route('hr.leave.index'))->assertForbidden();
    $this->actingAs($this->pos['manager'])->post(route('hr.leave.store'), [
        'employee_id' => $this->nimal->id, 'leave_type_id' => $this->annual->id, 'from_date' => '2026-10-05',
    ])->assertForbidden();
});
