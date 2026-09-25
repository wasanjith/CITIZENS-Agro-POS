<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Validator;

/**
 * Checks shared by document line editors: the variant belongs to the product, a product
 * with variants has one chosen, the unit is set up for the product and whole-number
 * units get whole numbers.
 */
trait ValidatesProductLines
{
    /**
     * @param  list<string>  $qtyFields  line fields holding quantities in the line unit
     */
    protected function validateProductLines(Validator $validator, string $key = 'lines', array $qtyFields = ['qty']): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $lines = (array) $this->input($key, []);
        $products = Product::query()
            ->with('units.unit')
            ->whereIn('id', array_filter(array_column($lines, 'product_id')))
            ->get()
            ->keyBy('id');
        $variants = ProductVariant::query()
            ->whereIn('id', array_filter(array_column($lines, 'variant_id')))
            ->get()
            ->keyBy('id');

        foreach ($lines as $index => $line) {
            $product = $products->get((int) ($line['product_id'] ?? 0));

            if ($product === null) {
                continue;
            }

            $variantId = $line['variant_id'] ?? null;

            if ($variantId !== null && $variantId !== '') {
                if ($variants->get((int) $variantId)?->product_id !== $product->id) {
                    $validator->errors()->add("{$key}.{$index}.variant_id", "The variant does not belong to {$product->name}.");
                }
            } elseif ($product->has_variants) {
                $validator->errors()->add("{$key}.{$index}.variant_id", "Choose the size/colour of {$product->name}.");
            }

            if (! array_key_exists('unit_id', $line)) {
                continue;
            }

            $unit = $product->units->firstWhere('unit_id', (int) $line['unit_id']);

            if ($unit === null) {
                $validator->errors()->add("{$key}.{$index}.unit_id", "{$product->name} is not bought or sold in this unit.");

                continue;
            }

            if (! $unit->unit->allows_decimal) {
                foreach ($qtyFields as $field) {
                    $value = (string) ($line[$field] ?? '0');

                    if (is_numeric($value) && ! BigDecimal::of($value)->getFractionalPart()->isZero()) {
                        $validator->errors()->add("{$key}.{$index}.{$field}", "{$unit->unit->name} must be a whole number.");
                    }
                }
            }
        }
    }
}
