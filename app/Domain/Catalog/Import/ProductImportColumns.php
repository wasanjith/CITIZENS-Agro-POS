<?php

namespace App\Domain\Catalog\Import;

/**
 * Columns of the product import template, with the help text shown on the import page.
 */
final class ProductImportColumns
{
    /**
     * @return array<string, string> heading => description
     */
    public static function all(): array
    {
        return [
            'short_code' => 'Code staff type at the counter. Leave empty to get the next free code of the category.',
            'name' => 'Required. English name.',
            'name_si' => 'Sinhala name.',
            'name_ta' => 'Tamil name.',
            'aliases' => 'Other names staff use, separated by commas (yuriya, u50).',
            'category' => 'Required. Existing category name, or "Parent > Child".',
            'brand' => 'Brand name. New brands are created automatically.',
            'base_unit' => 'Required. Unit stock is counted in (kg, piece, litre …).',
            'sale_units' => 'Other units and how many base units they hold: "bag=50; packet=5".',
            'default_sale_unit' => 'Unit selected first at the counter. Empty = base unit.',
            'retail_price' => 'Retail price of the default sale unit. Other units are worked out from it.',
            'wholesale_price' => 'Wholesale price of the default sale unit.',
            'reorder_level' => 'Warn when stock (in base units) falls to this level.',
            'reorder_qty' => 'Suggested quantity to order (base units).',
            'opening_stock' => 'Stock on hand now, in base units.',
            'cost' => 'Buying cost of one base unit.',
            'batch_no' => 'Batch or lot number of the opening stock.',
            'expiry_date' => 'Expiry date of the opening stock (YYYY-MM-DD).',
        ];
    }

    /**
     * @return list<string>
     */
    public static function headings(): array
    {
        return array_keys(self::all());
    }
}
