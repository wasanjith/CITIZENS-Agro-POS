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
 * They follow the shop's real pricing:
 *   - every sealed pack is its own product (Okra 10g / 50g / 100g / 250g packet, Urea 50kg bag);
 *   - loose fertilizer is weighed out, with a price per kg under 1 kg and a cheaper one from 1 kg;
 *   - sealed bags open into their loose product (Inventory → Open packs, or "Open now" on a GRN);
 *   - prices are optional: no wholesale price → retail; Okra 250g has no price yet.
 */
class DevelopmentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $units = Unit::pluck('id', 'name');
        $lists = PriceList::pluck('id', 'name');
        $category = fn (string $name) => Category::where('name', $name)->value('id');
        $brand = fn (string $name) => Brand::firstOrCreate(['name' => $name])->id;

        $okra = fn (string $code, string $size, ?string $cost, ?string $price, int $reorder) => [
            'code' => $code, 'name' => "Okra Haritha {$size} packet", 'si' => "බණ්ඩක්කා (හරිත) {$size}", 'aliases' => 'bandakka, okra seed',
            'category' => 'Seeds', 'brand' => 'Bathalagoda Agro', 'unit' => 'packet', 'cost' => $cost, 'reorder' => $reorder,
            'prices' => $price !== null ? ['Retail' => ['packet' => $price]] : [],
        ];

        $products = [
            // Loose fertilizer: Retail kg = under 1 kg, kg@1 = from 1 kg (both per kg).
            ['code' => '1101', 'name' => 'Urea (loose)', 'si' => 'යූරියා (ලිහිල්)', 'aliases' => 'yuriya, urea', 'category' => 'Fertilizers', 'brand' => 'Lanka Fertilizer',
                'unit' => 'kg', 'loose' => true, 'cost' => '200', 'reorder' => 25, 'attributes' => ['NPK' => '46-0-0'],
                'prices' => ['Retail' => ['kg' => '300.00', 'kg@1' => '250.00'], 'Wholesale' => ['kg@1' => '240.00']]],
            ['code' => '1102', 'name' => 'TSP (loose)', 'si' => 'ටී.එස්.පී. (ලිහිල්)', 'aliases' => 'tsp, triple super, pospet', 'category' => 'Fertilizers', 'brand' => 'Lanka Fertilizer',
                'unit' => 'kg', 'loose' => true, 'cost' => '216', 'reorder' => 25, 'attributes' => ['NPK' => '0-46-0'],
                'prices' => ['Retail' => ['kg' => '320.00', 'kg@1' => '270.00']]],
            ['code' => '1103', 'name' => 'MOP (loose)', 'si' => 'එම්.ඕ.පී. (ලිහිල්)', 'aliases' => 'mop, potash, pottasiyam', 'category' => 'Fertilizers', 'brand' => 'Baur',
                'unit' => 'kg', 'loose' => true, 'cost' => '230', 'reorder' => 25, 'attributes' => ['NPK' => '0-0-60'],
                'prices' => ['Retail' => ['kg' => '340.00', 'kg@1' => '290.00']]],

            // Sealed bags, sold whole or opened into the loose product.
            ['code' => '1001', 'name' => 'Urea 50kg bag', 'si' => 'යූරියා 50kg මල්ල', 'aliases' => 'urea bag, u50', 'category' => 'Fertilizers', 'brand' => 'Lanka Fertilizer',
                'unit' => 'bag', 'cost' => '10000', 'reorder' => 5, 'opens_into' => ['1101', '50'], 'attributes' => ['NPK' => '46-0-0'],
                'prices' => ['Retail' => ['bag' => '12000.00'], 'Wholesale' => ['bag' => '11800.00']]],
            ['code' => '1002', 'name' => 'TSP 50kg bag', 'si' => 'ටී.එස්.පී. 50kg මල්ල', 'aliases' => 'tsp bag', 'category' => 'Fertilizers', 'brand' => 'Lanka Fertilizer',
                'unit' => 'bag', 'cost' => '10800', 'reorder' => 5, 'opens_into' => ['1102', '50'],
                'prices' => ['Retail' => ['bag' => '13000.00'], 'Wholesale' => ['bag' => '12800.00']]],
            ['code' => '1003', 'name' => 'MOP 50kg bag', 'si' => 'එම්.ඕ.පී. 50kg මල්ල', 'aliases' => 'mop bag, potash bag', 'category' => 'Fertilizers', 'brand' => 'Baur',
                'unit' => 'bag', 'cost' => '11500', 'reorder' => 5, 'opens_into' => ['1103', '50'],
                'prices' => ['Retail' => ['bag' => '14000.00'], 'Wholesale' => ['bag' => '13700.00']]],
            ['code' => '1201', 'name' => 'Foliar Fertilizer 500ml bottle', 'si' => 'පත්‍ර පොහොර 500ml', 'aliases' => 'liquid fertilizer, foliar', 'category' => 'Fertilizers', 'brand' => 'Hayleys Agro',
                'unit' => 'bottle', 'cost' => '700', 'reorder' => 10,
                'prices' => ['Retail' => ['bottle' => '950.00'], 'Wholesale' => ['bottle' => '900.00']]],

            // Seed packets: each pack size is its own product (prices from the owner's sheet).
            $okra('2011', '10g', '72', '120.00', 50),
            $okra('2012', '50g', '192', '290.00', 15),
            $okra('2013', '100g', '348', '530.00', 15),
            $okra('2014', '250g', null, null, 5),
            ['code' => '2001', 'name' => 'Big Onion Seeds 50g packet', 'si' => 'ලොකු ළූණු බීජ 50g', 'aliases' => 'lunu, onion seed', 'category' => 'Seeds', 'brand' => 'CIC',
                'unit' => 'packet', 'cost' => '1150', 'reorder' => 10, 'prices' => ['Retail' => ['packet' => '1450.00'], 'Wholesale' => ['packet' => '1400.00']]],
            ['code' => '2002', 'name' => 'Chilli Seeds MI-2 10g packet', 'si' => 'මිරිස් බීජ 10g', 'aliases' => 'miris, chili seed', 'category' => 'Seeds', 'brand' => 'CIC',
                'unit' => 'packet', 'cost' => '260', 'reorder' => 10, 'prices' => ['Retail' => ['packet' => '350.00']]],

            ['code' => '3001', 'name' => 'Glyphosate 1L bottle', 'si' => 'ග්ලයිෆොසේට් 1L', 'aliases' => 'roundup, weed killer, wal nasaka', 'category' => 'Herbicides', 'brand' => 'Hayleys Agro',
                'unit' => 'bottle', 'cost' => '1950', 'reorder' => 10, 'prices' => ['Retail' => ['bottle' => '2400.00'], 'Wholesale' => ['bottle' => '2300.00']]],
            ['code' => '3002', 'name' => 'Mancozeb 1kg packet', 'si' => 'මැන්කොසෙබ් 1kg', 'aliases' => 'dithane, fungicide', 'category' => 'Fungicides', 'brand' => 'Hayleys Agro',
                'unit' => 'packet', 'cost' => '1300', 'reorder' => 10, 'prices' => ['Retail' => ['packet' => '1650.00'], 'Wholesale' => ['packet' => '1600.00']]],
            ['code' => '4001', 'name' => 'Mammoty', 'si' => 'උදැල්ල', 'aliases' => 'udalla, hoe', 'category' => 'Tools', 'brand' => null,
                'unit' => 'piece', 'cost' => '1450', 'reorder' => 10, 'prices' => ['Retail' => ['piece' => '1850.00'], 'Wholesale' => ['piece' => '1800.00']]],

            // Bicycle parts: sizes with different prices are separate products; the tube's sizes
            // share one price, so they stay variants of one product.
            ['code' => '5001', 'name' => 'Bicycle Tyre 26"', 'si' => 'බයිසිකල් ටයරය 26"', 'aliases' => 'tyre, tire, bike tyre', 'category' => 'Tyres', 'brand' => 'DSI',
                'unit' => 'piece', 'cost' => '1800', 'reorder' => 5, 'prices' => ['Retail' => ['piece' => '2250.00'], 'Wholesale' => ['piece' => '2150.00']]],
            ['code' => '5002', 'name' => 'Bicycle Tyre 28"', 'si' => 'බයිසිකල් ටයරය 28"', 'aliases' => 'tyre, tire, bike tyre', 'category' => 'Tyres', 'brand' => 'DSI',
                'unit' => 'piece', 'cost' => '1950', 'reorder' => 5, 'prices' => ['Retail' => ['piece' => '2450.00'], 'Wholesale' => ['piece' => '2350.00']]],
            ['code' => '5010', 'name' => 'Bicycle Tube', 'si' => 'බයිසිකල් ටියුබ්', 'aliases' => 'tube, tyre tube', 'category' => 'Tubes', 'brand' => 'DSI',
                'unit' => 'piece', 'cost' => '620', 'reorder' => 10, 'prices' => ['Retail' => ['piece' => '850.00'], 'Wholesale' => ['piece' => '800.00']],
                'variants' => ['5011' => '26"', '5012' => '28"']],
            ['code' => '5020', 'name' => 'Bicycle Chain', 'si' => 'බයිසිකල් දම්වැල', 'aliases' => 'chain, damwela', 'category' => 'Chains', 'brand' => 'KMC',
                'unit' => 'piece', 'cost' => '1000', 'reorder' => 10, 'attributes' => ['speed' => '1-speed'], 'prices' => ['Retail' => ['piece' => '1350.00'], 'Wholesale' => ['piece' => '1300.00']]],
            ['code' => '5030', 'name' => 'Brake Cable', 'si' => 'බ්‍රේක් කේබලය', 'aliases' => 'break cable, brake wire', 'category' => 'Brakes', 'brand' => null,
                'unit' => 'piece', 'cost' => '180', 'reorder' => 20, 'prices' => ['Retail' => ['piece' => '280.00']]],
        ];

        foreach ($products as $row) {
            $variants = $row['variants'] ?? [];

            $product = Product::updateOrCreate(['short_code' => $row['code']], [
                'name' => $row['name'],
                'name_si' => $row['si'],
                'aliases' => $row['aliases'],
                'category_id' => $category($row['category']),
                'brand_id' => $row['brand'] ? $brand($row['brand']) : null,
                'base_unit_id' => $units[$row['unit']],
                'sold_loose' => $row['loose'] ?? false,
                'opens_into_product_id' => isset($row['opens_into']) ? Product::where('short_code', $row['opens_into'][0])->value('id') : null,
                'opens_into_qty' => $row['opens_into'][1] ?? null,
                'has_variants' => $variants !== [],
                'reorder_level' => $row['reorder'],
                'reorder_qty' => $row['reorder'] * 2,
                'reference_cost' => $row['cost'],
                'min_selling_margin_pct' => 5,
                'attributes' => ($row['attributes'] ?? []) ?: null,
                'is_active' => true,
            ]);

            ProductUnit::updateOrCreate(['product_id' => $product->id, 'unit_id' => $units[$row['unit']]], [
                'factor' => 1,
                'is_default_sale' => true,
                'is_default_purchase' => true,
            ]);

            foreach ($row['prices'] as $list => $byUnit) {
                foreach ($byUnit as $unit => $price) {
                    [$unitName, $minQty] = array_pad(explode('@', $unit, 2), 2, '0');

                    ProductPrice::firstOrCreate(
                        ['product_id' => $product->id, 'unit_id' => $units[$unitName], 'price_list_id' => $lists[$list], 'min_qty' => $minQty],
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
            'okra' => ['bandakka'],
        ];

        foreach ($synonyms as $term => $words) {
            SearchSynonym::updateOrCreate(['term' => $term], ['synonyms' => $words]);
        }

        // Index again now that units and variants exist.
        $products = Product::query()->with(['category.parent.parent', 'brand', 'variants'])->get();
        $products->first()?->searchableUsing()->update($products);
    }
}
