<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\Holiday;
use App\Domain\HR\Models\LeaveType;
use App\Domain\HR\Models\Shift;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Shifts (working hours), holidays (Poya and public holidays) and leave types.
 */
class HrSetupController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Employee::class);

        $year = (int) $request->query('year', (string) today()->year);
        $year = $year >= 2020 && $year <= 2100 ? $year : today()->year;

        return view('hr.setup.index', [
            'shifts' => Shift::query()->withCount('employees')->orderBy('name')->get(),
            'holidays' => Holiday::query()->whereYear('date', $year)->orderBy('date')->get(),
            'year' => $year,
            'leaveTypes' => LeaveType::query()->orderBy('id')->get(),
            'days' => Shift::DAY_NAMES,
        ]);
    }

    public function storeShift(Request $request): RedirectResponse
    {
        $this->authorize('create', Employee::class);

        $this->saveShift(new Shift, $this->validateShift($request));

        return back()->with('success', 'Shift added.');
    }

    public function updateShift(Request $request, Shift $shift): RedirectResponse
    {
        $this->authorize('update', Employee::class);

        $this->saveShift($shift, $this->validateShift($request));

        return back()->with('success', "{$shift->name} saved. Days already clocked keep their late and overtime minutes.");
    }

    public function storeHoliday(Request $request): RedirectResponse
    {
        $this->authorize('create', Employee::class);

        $validated = $request->validate([
            'date' => ['required', 'date', 'unique:holidays,date'],
            'name' => ['required', 'string', 'max:100'],
        ], ['date.unique' => 'That day is already a holiday.']);

        Holiday::create($validated);

        return back()->with('success', "{$validated['name']} added.");
    }

    public function destroyHoliday(Holiday $holiday): RedirectResponse
    {
        $this->authorize('delete', Employee::class);

        $holiday->delete();

        return back()->with('success', "{$holiday->name} removed.");
    }

    public function storeLeaveType(Request $request): RedirectResponse
    {
        $this->authorize('create', Employee::class);

        LeaveType::create([...$this->validateLeaveType($request), 'is_active' => true]);

        return back()->with('success', 'Leave type added.');
    }

    public function updateLeaveType(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('update', Employee::class);

        $leaveType->update([...$this->validateLeaveType($request), 'is_active' => $request->boolean('is_active')]);

        return back()->with('success', "{$leaveType->name} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validateShift(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['integer', 'between:1,7'],
            'is_default' => ['nullable', 'boolean'],
        ], ['working_days.required' => 'Tick the working days.', 'end_time.after' => 'The shift must end after it starts.']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveShift(Shift $shift, array $data): void
    {
        DB::transaction(function () use ($shift, $data): void {
            $default = (bool) ($data['is_default'] ?? false) || ! Shift::query()->whereKeyNot($shift->id ?? 0)->exists();

            if ($default) {
                Shift::query()->whereKeyNot($shift->id ?? 0)->update(['is_default' => false]);
            }

            $shift->fill([
                ...$data,
                'working_days' => array_values(array_unique(array_map('intval', $data['working_days']))),
                'is_default' => $default,
            ])->save();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function validateLeaveType(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'days_per_year' => ['required', 'numeric', 'min:0', 'max:365'],
            'is_paid' => ['nullable', 'boolean'],
        ]);

        return [...$validated, 'is_paid' => $request->boolean('is_paid')];
    }
}
