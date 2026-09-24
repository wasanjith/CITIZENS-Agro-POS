<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Catalogue master data the shop needs before the first product: units,
 * price lists, a VAT rate (inactive until the owner confirms VAT registration)
 * and the top-level categories with their short code ranges.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            // name, Sinhala, symbol, allows decimal
            ['kg', 'කිලෝ', 'kg', true],
            ['g', 'ග්‍රෑම්', 'g', false],
            ['bag', 'මල්ල', 'bag', false],
            ['packet', 'පැකට්', 'pkt', false],
            ['piece', 'කෑලි', 'pc', false],
            ['pair', 'යුගල', 'pair', false],
            ['set', 'කට්ටල', 'set', false],
            ['litre', 'ලීටර්', 'L', true],
            ['ml', 'මිලි ලීටර්', 'ml', false],
            ['roll', 'රෝල්', 'roll', false],
            ['metre', 'මීටර්', 'm', true],
        ];

        foreach ($units as [$name, $nameSi, $symbol, $allowsDecimal]) {
            Unit::updateOrCreate(['name' => $name], ['name_si' => $nameSi, 'symbol' => $symbol, 'allows_decimal' => $allowsDecimal]);
        }

        foreach (['Retail' => true, 'Wholesale' => false, 'Farmer Credit' => false] as $name => $isDefault) {
            PriceList::updateOrCreate(['name' => $name], ['is_default' => $isDefault]);
        }

        Tax::firstOrCreate(['name' => 'VAT'], ['rate' => 18, 'is_active' => false]);

        $categories = [
            // name, Sinhala, code range, children
            ['Fertilizers', 'පොහොර', [1000, 1999], []],
            ['Seeds', 'බීජ', [2000, 2999], []],
            ['Agro-chemicals', 'කෘෂි රසායන', [3000, 3999], ['Insecticides', 'Fungicides', 'Herbicides']],
            ['Tools', 'උපකරණ', [4000, 4999], []],
            ['Bicycle Parts', 'බයිසිකල් කොටස්', [5000, 6999], ['Tyres', 'Tubes', 'Chains', 'Brakes', 'Gears']],
            ['Other', 'වෙනත්', [9000, 9999], []],
        ];

        foreach ($categories as $order => [$name, $nameSi, [$from, $to], $children]) {
            $parent = Category::updateOrCreate(
                ['name' => $name, 'parent_id' => null],
                ['name_si' => $nameSi, 'code_from' => $from, 'code_to' => $to, 'sort_order' => $order],
            );

            foreach ($children as $childOrder => $child) {
                Category::updateOrCreate(['name' => $child, 'parent_id' => $parent->id], ['sort_order' => $childOrder]);
            }
        }
    }
}
