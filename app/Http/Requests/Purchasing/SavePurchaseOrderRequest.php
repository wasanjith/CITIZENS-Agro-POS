<?php

namespace App\Http\Requests\Purchasing;

use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Http\Requests\Concerns\ValidatesProductLines;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SavePurchaseOrderRequest extends FormRequest
{
    use ValidatesProductLines;

    public function authorize(): bool
    {
        $order = $this->route('purchase_order');

        return $order instanceof PurchaseOrder
            ? $this->user()->can('update', $order)
            : $this->user()->can('create', PurchaseOrder::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,3'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'action' => ['nullable', 'in:save,submit'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Add at least one product.',
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateProductLines($validator)];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'lines' => array_values(array_map(fn ($line) => [
                ...(array) $line,
                'variant_id' => ($line['variant_id'] ?? null) ?: null,
                'unit_cost' => ($line['unit_cost'] ?? null) === '' ? null : ($line['unit_cost'] ?? null),
            ], (array) $this->input('lines', []))),
        ]);
    }

    public function wantsSubmit(): bool
    {
        return $this->input('action') === 'submit';
    }
}
