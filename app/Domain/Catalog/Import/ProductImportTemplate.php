<?php

namespace App\Domain\Catalog\Import;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Downloadable .xlsx template with the headings and example rows: seed packets (one
 * product per pack size), loose urea with its two prices, the sealed urea bag that is
 * opened into it, and a bicycle part.
 */
class ProductImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    use Exportable;

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ProductImportColumns::headings();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $rows = [
            ['name' => 'Okra', 'name_si' => 'බණ්ඩක්කා', 'category' => 'Seeds', 'brand' => 'Bathalagoda Agro', 'base_unit' => 'packet', 'pack_size' => '10g packet', 'cost' => 72, 'retail_price' => 120, 'reorder_level' => 50],
            ['pack_size' => '50g packet', 'cost' => 192, 'retail_price' => 290, 'reorder_level' => 15],
            ['pack_size' => '100g packet', 'cost' => 348, 'retail_price' => 530, 'reorder_level' => 15],
            ['name' => 'Urea (loose)', 'name_si' => 'යූරියා (ලිහිල්)', 'aliases' => 'yuriya', 'category' => 'Fertilizers', 'brand' => 'Lanka Fertilizer', 'base_unit' => 'kg', 'cost' => 200, 'loose_price_kg' => 250, 'loose_price_small' => 300, 'reorder_level' => 25],
            ['name' => 'Urea', 'name_si' => 'යූරියා', 'aliases' => 'urea bag, u50', 'category' => 'Fertilizers', 'brand' => 'Lanka Fertilizer', 'base_unit' => 'bag', 'pack_size' => '50kg bag', 'cost' => 10000, 'wholesale_price' => 11800, 'retail_price' => 12000, 'reorder_level' => 5, 'opens_into' => 'Urea (loose)', 'opens_into_qty' => 50],
            ['name' => 'Bicycle Tyre 26"', 'name_si' => 'බයිසිකල් ටයරය 26"', 'aliases' => 'tyre 26', 'category' => 'Bicycle Parts > Tyres', 'brand' => 'DSI', 'base_unit' => 'piece', 'opening_stock' => 40, 'cost' => 1800, 'wholesale_price' => 2150, 'retail_price' => 2250, 'reorder_level' => 5],
        ];

        return array_map(fn (array $row) => array_map(fn (string $key) => $row[$key] ?? '', ProductImportColumns::keys()), $rows);
    }
}
