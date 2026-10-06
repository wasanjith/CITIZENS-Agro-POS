<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Models\PackOpening;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenPacksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PackOpening::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sealed_product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')->whereNotNull('opens_into_product_id')],
            'packs' => ['required', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,3'],
            'weighed_qty' => ['nullable', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,3'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sealed_product_id.required' => 'Choose the sealed pack to open.',
            'sealed_product_id.exists' => 'This product is not set up to be opened into a loose product.',
            'packs.gt' => 'Enter how many packs to open.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['weighed_qty' => $this->input('weighed_qty') ?: null]);
    }
}
