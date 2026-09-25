<?php

namespace App\Http\Requests\Customers;

use App\Domain\Customers\Actions\SaveCustomerAction;
use App\Domain\Customers\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Customer form (back office). Authorization is done in the controller.
 */
class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => app(SaveCustomerAction::class)->normalisePhone($this->input('phone')),
            'is_active' => $this->boolean('is_active'),
            'credit_limit' => $this->input('credit_limit') ?: 0,
            'credit_days' => $this->input('credit_days') ?: 0,
            'opening_balance' => $this->input('opening_balance') ?: 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Customer|null $customer */
        $customer = $this->route('customer');

        return [
            'name' => ['required', 'string', 'max:150'],
            'name_si' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'min:9', 'max:15', Rule::unique('customers', 'phone')->ignore($customer?->id)->whereNull('deleted_at')],
            'nic' => ['nullable', 'string', 'max:20', 'regex:/^(\d{9}[VvXx]|\d{12})$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:100'],
            'price_list_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'credit_limit' => ['numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'credit_days' => ['integer', 'min:0', 'max:365'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'opening_balance' => ['numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => 'Another customer already has this phone number.',
            'nic.regex' => 'Enter an NIC like 881234567V or 198812345678.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customerData(bool $withOpeningBalance): array
    {
        $fields = ['name', 'name_si', 'phone', 'nic', 'address', 'area', 'price_list_id', 'credit_limit', 'credit_days', 'is_active', 'notes'];

        if ($withOpeningBalance) {
            $fields[] = 'opening_balance';
        }

        return $this->safe()->only($fields);
    }
}
