<?php

namespace App\Domain\Catalog\Import;

use Illuminate\Support\Str;

/**
 * Columns of the product import template: the heading in the file, the help text on
 * the import page, and other headings that mean the same column (the owner's own
 * price sheet says "Product Varients", "Whole Sale Price", "Price for Kg's" …).
 *
 * Headings are matched after Laravel Excel turns them into slugs ("Price for Kg's" → price_for_kgs).
 */
final class ProductImportColumns
{
    /**
     * @return array<string, array{heading: string, description: string, aliases?: list<string>}> key => column
     */
    public static function all(): array
    {
        return [
            'short_code' => ['heading' => 'Short Code', 'description' => 'Code staff type at the counter. Leave empty to get the next free code of the category.', 'aliases' => ['code']],
            'name' => ['heading' => 'Product Name', 'description' => 'Required. English name. Leave empty on the next rows of the same product to add more pack sizes.'],
            'name_si' => ['heading' => 'Sinhala Name', 'description' => 'Sinhala name.'],
            'name_ta' => ['heading' => 'Tamil Name', 'description' => 'Tamil name.'],
            'aliases' => ['heading' => 'Aliases', 'description' => 'Other names staff use, separated by commas (yuriya, u50).'],
            'category' => ['heading' => 'Category', 'description' => 'Required. Existing category name, or "Parent > Child".'],
            'brand' => ['heading' => 'Brand', 'description' => 'Brand name. New brands are created automatically.'],
            'base_unit' => ['heading' => 'Base Unit', 'description' => 'Required. Unit stock is counted in: packet, bag, bottle, piece for sealed goods; kg for loose goods.'],
            'pack_size' => ['heading' => 'Pack Size', 'description' => 'Sealed pack, e.g. "10g packet". Each pack size becomes its own product: Okra + 10g packet → "Okra 10g packet".', 'aliases' => ['product_varients', 'product_variants', 'product_variant', 'pack']],
            'default_sale_unit' => ['heading' => 'Default Sale Unit', 'description' => 'Unit selected first at the counter. Empty = base unit.'],
            'opening_stock' => ['heading' => 'Opening Stock', 'description' => 'Stock on hand now, in the base unit (packets, bags, kg …).'],
            'cost' => ['heading' => 'Cost', 'description' => 'Buying cost of one base unit (one packet, one kg …).'],
            'wholesale_price' => ['heading' => 'Whole Sale Price', 'description' => 'Price for wholesale customers. Empty = they pay the selling price.'],
            'retail_price' => ['heading' => 'Selling Price', 'description' => 'Price of the default sale unit. Empty = cannot be billed until a price is set.', 'aliases' => ['retail_price', 'price']],
            'loose_price_kg' => ['heading' => "Price for Kg's (per kg)", 'description' => 'Loose goods only: price per kg when the customer buys 1 kg or more.', 'aliases' => ['price_for_kgs', 'price_for_kg', 'price_per_kg']],
            'loose_price_small' => ['heading' => 'Price for grams (per kg)', 'description' => 'Loose goods only: price per kg when the customer buys less than 1 kg. Write it per kg: Rs. 30 for 100 g is 300.', 'aliases' => ['price_for_grams', 'price_for_gram']],
            'reorder_level' => ['heading' => 'Reorder Level', 'description' => 'Warn when stock (in the base unit) falls to this level.'],
            'reorder_qty' => ['heading' => 'Reorder Qty', 'description' => 'Suggested quantity to order (base unit).'],
            'sale_units' => ['heading' => 'Other Units', 'description' => 'Bigger units it is bought or sold in and how many base units they hold: "bag=50". No price is worked out for them.', 'aliases' => ['sale_units']],
            'batch_no' => ['heading' => 'Batch No', 'description' => 'Batch or lot number of the opening stock.'],
            'expiry_date' => ['heading' => 'Expiry Date', 'description' => 'Expiry date of the opening stock (YYYY-MM-DD).'],
            'opens_into' => ['heading' => 'Opens Into', 'description' => 'Sealed bag that is sometimes opened and sold loose: the loose product\'s name or short code, e.g. "Urea (loose)".'],
            'opens_into_qty' => ['heading' => 'Loose Qty Per Pack', 'description' => 'How much loose product one pack gives, e.g. 50 (kg) for a 50 kg bag. Empty = read from the pack size.', 'aliases' => ['kg_per_pack']],
        ];
    }

    /**
     * Headings of the template, in column order.
     *
     * @return list<string>
     */
    public static function headings(): array
    {
        return array_values(array_map(fn (array $column) => $column['heading'], self::all()));
    }

    /**
     * Internal keys, in column order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * The key a file heading (as slug) stands for, or null for a column the import does not use.
     */
    public static function key(string $slug): ?string
    {
        static $map = null;

        if ($map === null) {
            $map = [];

            foreach (self::all() as $key => $column) {
                foreach ([$key, Str::slug($column['heading'], '_'), ...($column['aliases'] ?? [])] as $name) {
                    $map[$name] = $key;
                }
            }
        }

        return $map[$slug] ?? null;
    }
}
