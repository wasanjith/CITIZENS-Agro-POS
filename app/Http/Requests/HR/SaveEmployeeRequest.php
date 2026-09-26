<?php

namespace App\Http\Requests\HR;

use App\Domain\HR\Enums\EmploymentType;
use App\Domain\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Employee form. Authorization is done in the controller.
 */
class SaveEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'is_epf_member' => $this->boolean('is_epf_member'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Employee|null $employee */
        $employee = $this->route('employee');

        return [
            'full_name' => ['required', 'string', 'max:150'],
            'name_si' => ['nullable', 'string', 'max:150'],
            'nic' => ['nullable', 'string', 'max:20', 'regex:/^(\d{9}[VvXx]|\d{12})$/'],
            'dob' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:80'],
            'join_date' => ['required', 'date'],
            'leave_date' => ['nullable', 'date', 'after_or_equal:join_date'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_no' => ['nullable', 'string', 'max:40'],
            'epf_no' => ['nullable', 'string', 'max:30'],
            'is_epf_member' => ['boolean'],
            'is_active' => ['boolean'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('employees', 'user_id')->ignore($employee?->id)],
            'components' => ['array'],
            'components.*.assigned' => ['nullable', 'boolean'],
            'components.*.value' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nic.regex' => 'Enter an NIC like 851234567V or 198512345678.',
            'user_id.unique' => 'That login already belongs to another employee.',
        ];
    }
}
