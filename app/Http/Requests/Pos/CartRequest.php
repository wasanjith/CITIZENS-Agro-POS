<?php

namespace App\Http\Requests\Pos;

use App\Domain\Sales\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The cart as the counter screen sends it. Only ids, quantities and discounts are
 * taken; prices and totals are always worked out again on the server.
 */
class CartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pos.sell');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cart_uuid' => ['required', 'uuid'],
            'price_list_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'customer_id' => ['nullable', 'integer'],
            'quotation_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'tendered' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'bill_discount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'bill_approval_request_id' => ['nullable', 'integer'],
            'lines' => ['present', 'array', 'max:200'],
            'lines.*.key' => ['required', 'string', 'max:64'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.approval_request_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cart(): array
    {
        return $this->safe()->only(['cart_uuid', 'price_list_id', 'customer_id', 'quotation_id', 'payment_method', 'tendered', 'bill_discount', 'bill_approval_request_id', 'lines']);
    }
}
