<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Enums\AdjustmentReason;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Http\Requests\Concerns\ValidatesProductLines;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateStockAdjustmentRequest extends FormRequest
{
    use ValidatesProductLines;

    public function authorize(): bool
    {
        return $this->user()->can('create', StockAdjustment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            // Signed, in base units: + adds stock, − removes it.
            'lines.*.qty' => ['required', 'numeric', 'not_in:0', 'min:-99999999999', 'max:99999999999', 'decimal:0,3'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Add at least one product.',
            'lines.*.qty.not_in' => 'The quantity cannot be zero.',
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
                'batch_id' => ($line['batch_id'] ?? null) ?: null,
            ], (array) $this->input('lines', []))),
        ]);
    }
}
