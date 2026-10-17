<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Models\SearchSynonym;
use App\Domain\Catalog\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * The shop's products from the owner's sheet (docs/CITIZENS- Inventory.xlsx), for local
 * development (never run in production).
 *   - every seed packet size is its own product: "Okra Haritha" + 10g → "Okra Haritha 10g packet";
 *   - the two Okras and the two Radishes share a name in the sheet, so they get the variety / brand added;
 *   - each family gets a block of ten short codes (2011, 2012 … then 2021 …) so new sizes fit in;
 *   - blank cost or selling price in the sheet → no cost / no price yet (shows in the no-price filter);
 *   - the sheet has no wholesale prices and no opening stock.
 */
class DevelopmentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $units = Unit::pluck('id', 'name');
        $lists = PriceList::pluck('id', 'name');
        $seeds = Category::where('name', 'Seeds')->whereNull('parent_id')->value('id');
        $brand = fn (string $name) => Brand::firstOrCreate(['name' => $name])->id;

        // name, Sinhala, search aliases, brand, sizes: pack => [cost, retail price, reorder level]
        $families = [
            ['Okra Haritha', 'බණ්ඩක්කා(හරිත)', 'bandakka, okra seed, haritha', 'Bathalagoda Agro', [
                '10g' => [72, 120, 50], '50g' => [192, 290, 15], '100g' => [348, 530, 15], '250g' => [null, null, 5]]],
            ['Okra MI5', 'බණ්ඩක්කා MI5', 'bandakka, okra seed, mi5', 'Bathalagoda Agro', [
                '10g' => [72, 120, 50], '50g' => [312, 470, 15], '100g' => [540, 810, 15], '250g' => [null, null, 5]]],
            ['Mas Long Bean', 'මස් මෑ', 'mas ma, long bean', 'Bathalagoda Agro', [
                '10g' => [72, 120, 50], '50g' => [270, 410, 15], '100g' => [null, null, 15], '250g' => [null, null, 5]]],
            ['Bush Bean', 'පඳුරු මෑ(බුෂිටා)', 'paduru ma, bushita, bush bean', 'Bathalagoda Agro', [
                '10g' => [72, 120, 50], '50g' => [240, 360, 15], '100g' => [null, null, 15], '250g' => [null, null, 5]]],
            ['Radish', 'රාඹු(බීරළු)', 'rabu, beeralu, radish', 'Bathalagoda Agro', [
                '3g' => [48, 80, 50], '10g' => [90, 150, 50], '25g' => [null, null, 5]]],
            ['Hawari Polon Long Bean', 'හවරි පොළොන් මෑ', 'hawari polon ma, long bean', 'Bathalagoda Agro', [
                '10g' => [72, 120, 50], '50g' => [330, 500, 15], '100g' => [600, 900, 15], '250g' => [null, null, 5]]],
            ['Winged Bean', 'දඹළ', 'dambala, winged bean', 'Bathalagoda Agro', [
                '10g' => [72, 120, 50]]],
            ['Bonchi Bean', 'බෝංචි මෑ', 'bonchi ma, bonchi', 'Bathalagoda Agro', [
                '10g' => [72, 120, 50], '50g' => [240, 360, 15], '100g' => [420, 630, 5], '250g' => [960, 1360, 5]]],
            ['Long Bean A-9', 'ගන්නොරුව A-9', 'ma, long bean, a9, gannoruwa', 'Bathalagoda Agro', [
                '10g' => [102, 170, 50], '50g' => [432, 650, 15], '100g' => [840, 1260, 5]]],
            ['White Bitter Gourd', 'සුදු කරවිළ MC43', 'sudu karawila, karavila, bitter gourd, mc43', 'Bathalagoda Agro', [
                '2g' => [60, 100, 50], '10g' => [156, 240, 50], '50g' => [750, 1130, 5]]],
            ['Snake Gourd MI Short', 'කොට පතෝල', 'kota patola, pathola, snake gourd', 'Bathalagoda Agro', [
                '2g' => [60, 100, 50], '10g' => [204, 320, 50], '50g' => [null, null, 5]]],
            ['Ridge Gourd', 'වැටකොළු LA 33', 'watakolu, wetakolu, ridge gourd, la33', 'Bathalagoda Agro', [
                '2g' => [60, 100, 50], '10g' => [150, 250, 50], '50g' => [null, null, 5]]],
            ['Chili MI-2', 'මිරිස් MI-2', 'miris, chilli, chili seed, mi2', 'Ishara Lak Seeds', [
                '5g' => [55, 100, 50], '10g' => [82.5, 150, 50], '50g' => [396, 650, 15], '100g' => [781, 1210, 5], '250g' => [1941.5, 3000, 5]]],
            ['Radish Ishara Lak', 'රාඹු(බීරළු)', 'rabu, beeralu, radish', 'Ishara Lak Seeds', [
                '5g' => [55, 100, 50], '10g' => [88, 160, 50]]],
            ['Beans', 'බෝංචි', 'bonchi, beans', 'Bathalagoda Agro', [
                '10g' => [90, 150, 50]]],
            ['Spinach', 'නිවිති', 'niwithi, nivithi, spinach', 'Bathalagoda Agro', [
                '2g' => [60, 100, 0], '10g' => [150, 250, 0]]],
            // No pack size or prices in the sheet yet.
            ['Marrow', 'මල් කැකිරි', 'mal kekiri, marrow', 'Land Mark Seeds', [
                '' => [null, null, 0]]],
        ];

        foreach ($families as $index => [$name, $nameSi, $aliases, $brandName, $sizes]) {
            $code = 2000 + ($index + 1) * 10;

            foreach ($sizes as $pack => [$cost, $price, $reorder]) {
                $code++;

                $product = Product::updateOrCreate(['short_code' => (string) $code], [
                    'name' => $pack !== '' ? "{$name} {$pack} packet" : $name,
                    'name_si' => $pack !== '' ? "{$nameSi} {$pack}" : $nameSi,
                    'aliases' => $aliases,
                    'category_id' => $seeds,
                    'brand_id' => $brand($brandName),
                    'base_unit_id' => $units['packet'],
                    'sold_loose' => false,
                    'has_variants' => false,
                    'reorder_level' => $reorder,
                    'reorder_qty' => $reorder * 2,
                    'reference_cost' => $cost,
                    'min_selling_margin_pct' => 5,
                    'is_active' => true,
                ]);

                ProductUnit::updateOrCreate(['product_id' => $product->id, 'unit_id' => $units['packet']], [
                    'factor' => 1,
                    'is_default_sale' => true,
                    'is_default_purchase' => true,
                ]);

                if ($price !== null) {
                    ProductPrice::firstOrCreate(
                        ['product_id' => $product->id, 'unit_id' => $units['packet'], 'price_list_id' => $lists['Retail'], 'min_qty' => 0],
                        ['price' => $price, 'effective_from' => now()->subDay()],
                    );
                }
            }
        }

        $synonyms = [
            'okra' => ['bandakka'],
            'chili' => ['chilli', 'miris'],
            'radish' => ['rabu', 'beeralu'],
            'bean' => ['ma', 'bonchi'],
            'gourd' => ['karawila', 'patola', 'watakolu'],
        ];

        foreach ($synonyms as $term => $words) {
            SearchSynonym::updateOrCreate(['term' => $term], ['synonyms' => $words]);
        }

        // Index again now that units exist.
        $products = Product::query()->with(['category.parent.parent', 'brand', 'variants'])->get();
        $products->first()?->searchableUsing()->update($products);
    }
}
