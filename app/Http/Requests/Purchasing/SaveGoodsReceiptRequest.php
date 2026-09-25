<?php

namespace App\Http\Requests\Purchasing;

use App\Domain\Catalog\Models\Product;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Http\Requests\Concerns\ValidatesProductLines;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveGoodsReceiptRequest extends FormRequest
{
    use ValidatesProductLines;

    public function authorize(): bool
    {
        $receipt = $this->route('goods_receipt');

        return $receipt instanceof GoodsReceipt
            ? $this->user()->can('update', $receipt)
            : $this->user()->can('create', GoodsReceipt::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:50'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:1000'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.po_line_id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,3'],
            'lines.*.free_qty' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,3'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'lines.*.lot_no' => ['nullable', 'string', 'max:50'],
            'lines.*.mfg_date' => ['nullable', 'date', 'before_or_equal:today'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'action' => ['nullable', 'in:save,post'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Add at least one product.',
            'lines.*.unit_cost.required' => 'Enter the unit cost of every line.',
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateProductLines($validator, qtyFields: ['qty', 'free_qty']),
            function (Validator $validator): void {
                $lines = (array) $this->input('lines', []);
                $tracked = Product::query()->whereIn('id', array_filter(array_column($lines, 'product_id')))->where('track_expiry', true)->pluck('name', 'id');

                foreach ($lines as $index => $line) {
                    if ($tracked->has((int) ($line['product_id'] ?? 0)) && blank($line['expiry_date'] ?? null)) {
                        $validator->errors()->add("lines.{$index}.expiry_date", "Enter the expiry date of {$tracked[(int) $line['product_id']]}.");
                    }
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'lines' => array_values(array_map(fn ($line) => [
                ...(array) $line,
                'po_line_id' => ($line['po_line_id'] ?? null) ?: null,
                'variant_id' => ($line['variant_id'] ?? null) ?: null,
                'free_qty' => ($line['free_qty'] ?? null) ?: 0,
                'lot_no' => trim((string) ($line['lot_no'] ?? '')) ?: null,
                'mfg_date' => ($line['mfg_date'] ?? null) ?: null,
                'expiry_date' => ($line['expiry_date'] ?? null) ?: null,
            ], (array) $this->input('lines', []))),
        ]);
    }

    public function wantsPost(): bool
    {
        return $this->input('action') === 'post';
    }
}
