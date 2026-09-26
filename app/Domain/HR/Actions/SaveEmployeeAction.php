<?php

namespace App\Domain\HR\Actions;

use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\SalaryComponent;
use App\Domain\System\Enums\SequenceResetPeriod;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update an employee, their salary components and the link to their login.
 * users.employee_id is kept in step with employees.user_id.
 */
class SaveEmployeeAction
{
    public function __construct(private readonly DocumentNumber $numbers) {}

    /**
     * @param  array<string, mixed>  $data  validated form fields; components: [component_id => ['assigned' => bool, 'value' => string|null]]
     */
    public function handle(array $data, ?Employee $employee = null): Employee
    {
        $userId = filled($data['user_id'] ?? null) ? (int) $data['user_id'] : null;

        if ($userId !== null && Employee::withTrashed()->where('user_id', $userId)->when($employee, fn ($query) => $query->whereKeyNot($employee->id))->exists()) {
            throw ValidationException::withMessages(['user_id' => 'That login already belongs to another employee.']);
        }

        return DB::transaction(function () use ($data, $employee, $userId): Employee {
            $this->numbers->ensure('EMP', 'E-', 3, SequenceResetPeriod::Never);

            $employee ??= new Employee(['code' => $this->numbers->next('EMP')]);
            $previousUser = $employee->user_id;

            $employee->fill([
                'user_id' => $userId,
                'full_name' => $data['full_name'],
                'name_si' => $data['name_si'] ?? null,
                'nic' => filled($data['nic'] ?? null) ? mb_strtoupper((string) $data['nic']) : null,
                'dob' => $data['dob'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'designation' => $data['designation'] ?? null,
                'join_date' => $data['join_date'],
                'leave_date' => $data['leave_date'] ?? null,
                'employment_type' => $data['employment_type'],
                'shift_id' => filled($data['shift_id'] ?? null) ? (int) $data['shift_id'] : null,
                'basic_salary' => (string) $data['basic_salary'],
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account_no' => $data['bank_account_no'] ?? null,
                'epf_no' => $data['epf_no'] ?? null,
                'is_epf_member' => (bool) ($data['is_epf_member'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ])->save();

            $this->syncComponents($employee, $data['components'] ?? []);

            if ($previousUser !== $userId) {
                if ($previousUser !== null) {
                    User::query()->whereKey($previousUser)->update(['employee_id' => null]);
                }

                if ($userId !== null) {
                    User::query()->whereKey($userId)->update(['employee_id' => $employee->id]);
                }
            }

            return $employee;
        });
    }

    /**
     * @param  array<int|string, array{assigned?: bool|string|null, value?: string|null}>  $components
     */
    private function syncComponents(Employee $employee, array $components): void
    {
        $known = SalaryComponent::query()->pluck('id')->all();
        $sync = [];

        foreach ($components as $id => $component) {
            if (! in_array((int) $id, $known, true) || ! filter_var($component['assigned'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $sync[(int) $id] = ['value_override' => filled($component['value'] ?? null) ? (string) $component['value'] : null];
        }

        $employee->salaryComponents()->sync($sync);
    }
}
