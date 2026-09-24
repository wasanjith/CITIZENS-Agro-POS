<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ShortCodeGenerator;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveProductRequest extends FormRequest
{
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9\-]*$/';

    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            ? $this->user()->can('update', $product)
            : $this->user()->can('create', Product::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product instanceof Product ? $product->id : null;

        return [
            'short_code' => ['required', 'string', 'max:20', 'regex:'.self::CODE_PATTERN, $this->uniqueCode($productId, null)],
            'sku' => ['nullable', 'string', 'max:50', Rule::unique('products', 'sku')->ignore($productId)],
            'name' => ['required', 'string', 'max:150'],
            'name_si' => ['nullable', 'string', 'max:150'],
            'name_ta' => ['nullable', 'string', 'max:150'],
            'aliases' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'tax_id' => ['nullable', 'integer', 'exists:taxes,id'],
            'is_active' => ['boolean'],
            'track_batches' => ['boolean'],
            'track_expiry' => ['boolean'],
            'reorder_level' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'reorder_qty' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'reference_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'min_selling_margin_pct' => ['nullable', 'numeric', 'min:0', 'max:999'],

            'attribute_rows' => ['array', 'max:20'],
            'attribute_rows.*.key' => ['nullable', 'string', 'max:40'],
            'attribute_rows.*.value' => ['nullable', 'string', 'max:100'],

            'units' => ['array', 'max:10'],
            'units.*.unit_id' => ['required', 'integer', 'distinct', 'exists:units,id'],
            'units.*.factor' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'units.*.is_default_sale' => ['boolean'],
            'units.*.is_default_purchase' => ['boolean'],

            'prices' => ['array'],
            'prices.*.unit_id' => ['required', 'integer'],
            'prices.*.price_list_id' => ['required', 'integer', 'exists:price_lists,id'],
            'prices.*.price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],

            'variants' => ['array', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.short_code' => ['required', 'string', 'max:20', 'distinct', 'regex:'.self::CODE_PATTERN],
            'variants.*.name' => ['required', 'string', 'max:150'],
            'variants.*.sku' => ['nullable', 'string', 'max:50'],
            'variants.*.size' => ['nullable', 'string', 'max:40'],
            'variants.*.colour' => ['nullable', 'string', 'max:40'],
            'variants.*.is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $product = $this->route('product');
                $productId = $product instanceof Product ? $product->id : null;
                $codes = [mb_strtoupper((string) $this->input('short_code'))];

                foreach ((array) $this->input('variants', []) as $index => $variant) {
                    $code = (string) ($variant['short_code'] ?? '');

                    if (in_array($code, $codes, true)) {
                        $validator->errors()->add("variants.{$index}.short_code", "Short code {$code} is used twice on this product.");
                    } elseif ($code !== '' && app(ShortCodeGenerator::class)->isTaken($code, $productId, isset($variant['id']) ? (int) $variant['id'] : null)) {
                        $validator->errors()->add("variants.{$index}.short_code", "Short code {$code} is already used by another product.");
                    }

                    $codes[] = $code;
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $variants = array_values(array_map(fn ($variant) => [
            ...(array) $variant,
            'short_code' => mb_strtoupper(trim((string) ($variant['short_code'] ?? ''))),
            'is_active' => filter_var($variant['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], (array) $this->input('variants', [])));

        $units = array_values(array_map(fn ($unit) => [
            ...(array) $unit,
            'is_default_sale' => filter_var($unit['is_default_sale'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'is_default_purchase' => filter_var($unit['is_default_purchase'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], (array) $this->input('units', [])));

        $this->merge([
            'short_code' => mb_strtoupper(trim((string) $this->input('short_code'))),
            'sku' => $this->input('sku') ?: null,
            'is_active' => $this->boolean('is_active'),
            'track_batches' => $this->boolean('track_batches'),
            'track_expiry' => $this->boolean('track_expiry'),
            'reorder_level' => $this->input('reorder_level') ?: 0,
            'reorder_qty' => $this->input('reorder_qty') ?: 0,
            'units' => $units,
            'variants' => $variants,
            'attribute_rows' => array_values((array) $this->input('attribute_rows', [])),
            'prices' => array_values((array) $this->input('prices', [])),
        ]);
    }

    /**
     * The validated data in the shape SaveProductAction expects.
     *
     * @return array<string, mixed>
     */
    public function productData(): array
    {
        $data = $this->validated();

        $attributes = [];
        foreach ($data['attribute_rows'] ?? [] as $row) {
            if (filled($row['key'] ?? null) && filled($row['value'] ?? null)) {
                $attributes[trim($row['key'])] = trim($row['value']);
            }
        }
        $data['attributes'] = $attributes ?: null;
        unset($data['attribute_rows']);

        $data['variants'] = array_map(fn (array $variant) => [
            'id' => $variant['id'] ?? null,
            'short_code' => $variant['short_code'],
            'name' => $variant['name'],
            'sku' => $variant['sku'] ?? null,
            'is_active' => $variant['is_active'] ?? true,
            'attributes' => array_filter(['size' => $variant['size'] ?? null, 'colour' => $variant['colour'] ?? null]) ?: null,
        ], $data['variants'] ?? []);

        $data['units'] ??= [];
        $data['prices'] ??= [];

        return $data;
    }

    private function uniqueCode(?int $productId, ?int $variantId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($productId, $variantId): void {
            if (app(ShortCodeGenerator::class)->isTaken((string) $value, $productId, $variantId)) {
                $fail("Short code {$value} is already used by another product.");
            }
        };
    }
}
