<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\SearchSynonym;
use App\Domain\Catalog\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Demo products for local development and search testing (never run in production).
 */
class DevelopmentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $units = Unit::pluck('id', 'name');
        $lists = PriceList::pluck('id', 'name');
        $category = fn (string $name) => Category::where('name', $name)->value('id');
        $brand = fn (string $name) => Brand::firstOrCreate(['name' => $name])->id;

        $products = [
            // code, name, Sinhala, aliases, category, brand, base unit, [unit => factor], default sale unit,
            // [unit => [retail, wholesale]], cost per base unit, attributes, variants [code => name]
            ['1001', 'Urea 50kg', 'යූරියා', 'yuriya, urea bag, u50', 'Fertilizers', 'Lanka Fertilizer', 'kg', ['bag' => 50], 'bag',
                ['kg' => ['190.00', '185.00'], 'bag' => ['9000.00', '8800.00']], '160', ['NPK' => '46-0-0'], []],
            ['1002', 'Triple Super Phosphate (TSP) 50kg', 'ටී.එස්.පී.', 'tsp, triple super, pospet', 'Fertilizers', 'Lanka Fertilizer', 'kg', ['bag' => 50], 'bag',
                ['kg' => ['210.00', '205.00'], 'bag' => ['10000.00', '9800.00']], '175', ['NPK' => '0-46-0'], []],
            ['1003', 'Muriate of Potash (MOP) 50kg', 'එම්.ඕ.පී.', 'mop, potash, pottasiyam', 'Fertilizers', 'Baur', 'kg', ['bag' => 50], 'bag',
                ['kg' => ['230.00', '225.00'], 'bag' => ['11000.00', '10800.00']], '190', ['NPK' => '0-0-60'], []],
            ['2001', 'Big Onion Seeds 50g', 'ලොකු ළූණු බීජ', 'lunu, onion seed', 'Seeds', 'CIC', 'packet', [], 'packet',
                ['packet' => ['1450.00', '1400.00']], '1150', [], []],
            ['2002', 'Chilli Seeds MI-2 10g', 'මිරිස් බීජ', 'miris, chili seed', 'Seeds', 'CIC', 'packet', [], 'packet',
                ['packet' => ['350.00', '330.00']], '260', [], []],
            ['3001', 'Glyphosate 1L', 'ග්ලයිෆොසේට්', 'roundup, weed killer, wal nasaka', 'Herbicides', 'Hayleys Agro', 'litre', [], 'litre',
                ['litre' => ['2400.00', '2300.00']], '1950', [], []],
            ['3002', 'Mancozeb 1kg', 'මැන්කොසෙබ්', 'dithane, fungicide', 'Fungicides', 'Hayleys Agro', 'packet', [], 'packet',
                ['packet' => ['1650.00', '1600.00']], '1300', [], []],
            ['4001', 'Mammoty', 'උදැල්ල', 'udalla, hoe', 'Tools', null, 'piece', [], 'piece',
                ['piece' => ['1850.00', '1800.00']], '1450', [], []],
            ['5001', 'Bicycle Tyre', 'බයිසිකල් ටයරය', 'tyre, tire, bike tyre', 'Tyres', 'DSI', 'piece', [], 'piece',
                ['piece' => ['2250.00', '2150.00']], '1800', [], ['5002' => '26"', '5003' => '28"']],
            ['5010', 'Bicycle Tube', 'බයිසිකල් ටියුබ්', 'tube, tyre tube', 'Tubes', 'DSI', 'piece', [], 'piece',
                ['piece' => ['850.00', '800.00']], '620', [], ['5011' => '26"', '5012' => '28"']],
            ['5020', 'Bicycle Chain', 'බයිසිකල් දම්වැල', 'chain, damwela', 'Chains', 'KMC', 'piece', [], 'piece',
                ['piece' => ['1350.00', '1300.00']], '1000', ['speed' => '1-speed'], []],
        ];

        foreach ($products as [$code, $name, $nameSi, $aliases, $categoryName, $brandName, $baseUnit, $extraUnits, $saleUnit, $prices, $cost, $attributes, $variants]) {
            $product = Product::updateOrCreate(['short_code' => $code], [
                'name' => $name,
                'name_si' => $nameSi,
                'aliases' => $aliases,
                'category_id' => $category($categoryName),
                'brand_id' => $brandName ? $brand($brandName) : null,
                'base_unit_id' => $units[$baseUnit],
                'has_variants' => $variants !== [],
                'reorder_level' => 10,
                'reorder_qty' => 20,
                'reference_cost' => $cost,
                'min_selling_margin_pct' => 5,
                'attributes' => $attributes ?: null,
                'is_active' => true,
            ]);

            foreach ([$baseUnit => 1, ...$extraUnits] as $unit => $factor) {
                ProductUnit::updateOrCreate(['product_id' => $product->id, 'unit_id' => $units[$unit]], [
                    'factor' => $factor,
                    'is_default_sale' => $unit === $saleUnit,
                    'is_default_purchase' => $unit === array_key_last([$baseUnit => 1, ...$extraUnits]),
                ]);
            }

            foreach ($prices as $unit => [$retail, $wholesale]) {
                foreach (['Retail' => $retail, 'Wholesale' => $wholesale] as $list => $price) {
                    ProductPrice::firstOrCreate(
                        ['product_id' => $product->id, 'unit_id' => $units[$unit], 'price_list_id' => $lists[$list]],
                        ['price' => $price, 'effective_from' => now()->subDay()],
                    );
                }
            }

            foreach ($variants as $variantCode => $variantName) {
                ProductVariant::updateOrCreate(['short_code' => $variantCode], ['product_id' => $product->id, 'name' => $variantName, 'attributes' => ['size' => $variantName]]);
            }
        }

        $synonyms = [
            'tsp' => ['triple super phosphate'],
            'mop' => ['muriate of potash', 'potash'],
            'tube' => ['tyre tube'],
            'mammoty' => ['udalla', 'hoe'],
        ];

        foreach ($synonyms as $term => $words) {
            SearchSynonym::updateOrCreate(['term' => $term], ['synonyms' => $words]);
        }

        // Index again now that units and variants exist.
        $products = Product::query()->with(['category.parent.parent', 'brand', 'variants'])->get();
        $products->first()?->searchableUsing()->update($products);
    }
}
