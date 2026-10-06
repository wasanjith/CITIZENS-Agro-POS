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
            'sold_loose' => ['boolean'],
            'opens_into_product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'opens_into_qty' => ['nullable', 'required_with:opens_into_product_id', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,3'],
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
            'prices.*.min_qty' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,3'],
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opens_into_qty.required_with' => 'Enter how much of the loose product one pack holds (e.g. 50 for a 50 kg bag).',
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateLoosePricing($validator),
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

    /**
     * Quantity tiers only for loose products; a pack opens into a loose product, never itself.
     */
    private function validateLoosePricing(Validator $validator): void
    {
        $product = $this->route('product');
        $soldLoose = $this->boolean('sold_loose');
        $seen = [];

        foreach ((array) $this->input('prices', []) as $index => $row) {
            $minQty = is_numeric($row['min_qty'] ?? null) ? (float) $row['min_qty'] : 0.0;

            if ($minQty > 0 && ! $soldLoose && filled($row['price'] ?? null)) {
                $validator->errors()->add("prices.{$index}.min_qty", 'Quantity prices are only for products sold loose.');
            }

            $key = ($row['price_list_id'] ?? '').':'.($row['unit_id'] ?? '').':'.$minQty;

            if (isset($seen[$key])) {
                $validator->errors()->add("prices.{$index}.min_qty", 'The same price is entered twice.');
            }

            $seen[$key] = true;
        }

        $targetId = $this->input('opens_into_product_id');

        if ($targetId === null) {
            return;
        }

        $target = Product::query()->find((int) $targetId);

        if ($product instanceof Product && $target?->id === $product->id) {
            $validator->errors()->add('opens_into_product_id', 'A product cannot be opened into itself.');
        } elseif ($target !== null && ! $target->sold_loose) {
            $validator->errors()->add('opens_into_product_id', "{$target->name} is not sold loose. Tick \"Sold loose\" on it first.");
        }

        if ($soldLoose) {
            $validator->errors()->add('opens_into_product_id', 'A loose product cannot be opened into another product. Only sealed packs can.');
        }

        if ((array) $this->input('variants', []) !== []) {
            $validator->errors()->add('opens_into_product_id', 'A product with variants cannot be opened into a loose product.');
        }
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
            'sold_loose' => $this->boolean('sold_loose'),
            'opens_into_product_id' => $this->input('opens_into_product_id') ?: null,
            'opens_into_qty' => $this->input('opens_into_product_id') ? ($this->input('opens_into_qty') ?: null) : null,
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
