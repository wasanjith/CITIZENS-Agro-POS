<?php

use App\Domain\Catalog\Import\ProductImportColumns;
use App\Domain\Catalog\Import\ProductsExport;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\OpeningStockEntry;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\PriceBook;
use App\Domain\Identity\Enums\Role;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
    $this->owner = userWithRole(Role::SuperAdmin);
});

/**
 * @param  list<array<string, mixed>>  $rows  keyed by column key
 * @param  array<string, string>|null  $headings  column key => heading in the file (default: the template's)
 */
function importFile(array $rows, ?array $headings = null): UploadedFile
{
    $headings ??= array_combine(ProductImportColumns::keys(), ProductImportColumns::headings());
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, array_values($headings), escape: '');

    foreach ($rows as $row) {
        fputcsv($handle, array_map(fn ($column) => $row[$column] ?? '', array_keys($headings)), escape: '');
    }

    rewind($handle);
    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
    file_put_contents($path, stream_get_contents($handle));

    return new UploadedFile($path, 'products.csv', 'text/csv', null, true);
}

function ureaRow(array $overrides = []): array
{
    return [
        'short_code' => '1001', 'name' => 'Urea 50kg', 'name_si' => 'යූරියා', 'aliases' => 'yuriya, u50',
        'category' => 'Fertilizers', 'brand' => 'Lanka Fertilizer', 'base_unit' => 'kg',
        'sale_units' => 'bag=50', 'default_sale_unit' => 'bag', 'retail_price' => '9000', 'wholesale_price' => '8800',
        'reorder_level' => '100', 'reorder_qty' => '500', 'opening_stock' => '1250', 'cost' => '160',
        'batch_no' => 'L-2409', 'expiry_date' => '2027-06-30',
        ...$overrides,
    ];
}

test('the template downloads', function () {
    $this->actingAs($this->owner)->get(route('catalog.products.import.template'))->assertOk()->assertDownload('product-import-template.xlsx');
});

test('a clean file is previewed, then imported with units, prices and opening stock', function () {
    $response = $this->actingAs($this->owner)->post(route('catalog.products.import.preview'), [
        'file' => importFile([
            ureaRow(),
            ureaRow(['short_code' => '', 'name' => 'Bicycle Tyre 26"', 'name_si' => '', 'category' => 'Bicycle Parts > Tyres', 'brand' => 'DSI',
                'base_unit' => 'piece', 'sale_units' => '', 'default_sale_unit' => '', 'retail_price' => '2250', 'wholesale_price' => '',
                'opening_stock' => '40', 'cost' => '1800', 'batch_no' => '', 'expiry_date' => '']),
        ]),
    ])->assertOk()->assertSee('Import 2 products');

    expect(Product::count())->toBe(0);

    $token = $response->viewData('token');
    $this->actingAs($this->owner)->post(route('catalog.products.import.store', $token))
        ->assertRedirect(route('catalog.products.index'))
        ->assertSessionHas('success', '2 products imported.');

    $urea = Product::with('units.unit')->firstWhere('short_code', '1001');
    $retail = PriceList::firstWhere('name', 'Retail');
    $prices = app(PriceBook::class)->forProduct($urea->id);
    $kgId = $urea->base_unit_id;
    $bagId = $urea->units->firstWhere('unit.name', 'bag')->unit_id;

    expect($urea->name_si)->toBe('යූරියා')
        ->and($urea->defaultSaleUnit()->unit->name)->toBe('bag')
        ->and($prices[$retail->id][$bagId])->toBe('9000.00')
        // Only the prices the file gives: nothing is worked out for the kg.
        ->and($prices[$retail->id])->not->toHaveKey($kgId)
        ->and($urea->reference_cost)->toBe('160.0000')
        ->and($urea->track_batches)->toBeTrue()
        ->and(Brand::where('name', 'Lanka Fertilizer')->exists())->toBeTrue();

    $opening = OpeningStockEntry::firstWhere('product_id', $urea->id);
    expect($opening->qty)->toBe('1250.000')
        ->and($opening->lot_no)->toBe('L-2409')
        ->and($opening->expiry_date->toDateString())->toBe('2027-06-30');

    // The empty code got the first free code of the Bicycle Parts range.
    expect(Product::firstWhere('name', 'Bicycle Tyre 26"')->short_code)->toBe('5000');
});

test('rows with unknown units, unknown categories or duplicate codes are reported by row number', function () {
    createProduct(['short_code' => '1005', 'category' => 'Fertilizers']);

    $response = $this->actingAs($this->owner)->post(route('catalog.products.import.preview'), [
        'file' => importFile([
            ureaRow(),                                               // row 2: fine
            ureaRow(['short_code' => '1002', 'base_unit' => 'sack']), // row 3: unknown unit
            ureaRow(['short_code' => '1001']),                        // row 4: duplicate in file
            ureaRow(['short_code' => '1005']),                        // row 5: already exists
            ureaRow(['short_code' => '1006', 'category' => 'Toys']),  // row 6: unknown category
            ureaRow(['short_code' => '1007', 'retail_price' => 'abc', 'sale_units' => 'bag=fifty']), // row 7
        ]),
    ])->assertOk()->assertDontSee('Import 1 products');

    $errors = $response->viewData('rowErrors');

    expect(array_keys($errors))->toBe([3, 4, 5, 6, 7])
        ->and($errors[3])->toContain('Unknown unit "sack".')
        ->and($errors[4])->toContain('Short code 1001 appears more than once in the file.')
        ->and($errors[5])->toContain('Short code 1005 already exists.')
        ->and($errors[6])->toContain('Unknown category "Toys".')
        ->and(implode(' ', $errors[7]))->toContain('Other unit "bag=fifty"')->toContain('Selling Price "abc"');

    $response->assertSee('Row 3')->assertSee('Unknown unit');
});

test('a file with errors cannot be imported even by posting the token', function () {
    $response = $this->actingAs($this->owner)->post(route('catalog.products.import.preview'), [
        'file' => importFile([ureaRow(['base_unit' => 'sack'])]),
    ]);

    $this->actingAs($this->owner)
        ->post(route('catalog.products.import.store', $response->viewData('token')))
        ->assertRedirect(route('catalog.products.import'));

    expect(Product::count())->toBe(0);
});

test('an unknown token is rejected', function () {
    $this->actingAs($this->owner)
        ->post(route('catalog.products.import.store', (string) Str::uuid()))
        ->assertNotFound();
});

test('sales staff cannot import', function () {
    $this->actingAs(userWithRole(Role::SalesStaff))
        ->post(route('catalog.products.import.preview'), ['file' => importFile([ureaRow()])])
        ->assertForbidden();
});

/**
 * Upload a file, check it and import it; returns the preview response.
 */
function previewAndImport(UploadedFile $file): TestResponse
{
    $test = test();
    $response = $test->actingAs($test->owner)->post(route('catalog.products.import.preview'), ['file' => $file])->assertOk();

    expect($response->viewData('rowErrors'))->toBe([]);

    $test->actingAs($test->owner)->post(route('catalog.products.import.store', $response->viewData('token')))
        ->assertRedirect(route('catalog.products.index'));

    return $response;
}

test("the owner's price sheet imports as it is: every pack size is its own product", function () {
    $headings = [
        'name' => 'Product Name', 'name_si' => 'Sinhala Name', 'aliases' => 'Aliases', 'category' => 'Category', 'brand' => 'Brand',
        'base_unit' => 'Base Unit', 'pack_size' => 'Product Varients', 'default_sale_unit' => 'Default Sale Unit', 'opening_stock' => 'Opening Stock',
        'cost' => 'Cost', 'wholesale_price' => 'Whole Sale Price', 'retail_price' => 'Selling Price', 'loose_price_kg' => "Price for Kg's",
        'loose_price_small' => 'Price for grams', 'reorder_level' => 'Reorder Level',
    ];
    $first = ['category' => 'Seeds', 'brand' => 'Bathalagoda Agro'];

    $response = previewAndImport(importFile([
        ['name' => 'Okra', 'name_si' => 'බණ්ඩක්කා(හරිත)', ...$first, 'base_unit' => 'packet', 'pack_size' => '10g packet', 'cost' => '72', 'retail_price' => '120', 'reorder_level' => '50'],
        ['pack_size' => '50g packet', 'cost' => '192', 'retail_price' => '290', 'reorder_level' => '15'],
        ['pack_size' => '100g packet', 'cost' => '348', 'retail_price' => '530', 'reorder_level' => '15'],
        ['pack_size' => '250g packet', 'reorder_level' => '5'],
        ['name' => 'Okra', 'name_si' => 'බණ්ඩක්කා MI5', ...$first, 'base_unit' => 'grams', 'pack_size' => '10g', 'cost' => '72', 'retail_price' => '120', 'reorder_level' => '50'],
        ['pack_size' => '50g', 'cost' => '312', 'retail_price' => '470', 'reorder_level' => '15'],
    ], $headings));

    $products = Product::query()->with(['baseUnit', 'category', 'brand'])->orderBy('id')->get();
    $retail = PriceList::firstWhere('name', 'Retail');
    $book = app(PriceBook::class);

    expect($products->pluck('name')->all())->toBe(['Okra 10g packet', 'Okra 50g packet', 'Okra 100g packet', 'Okra 250g packet', 'Okra 10g', 'Okra 50g'])
        ->and($products->pluck('baseUnit.name')->unique()->values()->all())->toBe(['packet'])
        ->and($products[1]->name_si)->toBe('බණ්ඩක්කා(හරිත) 50g packet')
        ->and($products[1]->category->name)->toBe('Seeds')
        ->and($products[1]->brand->name)->toBe('Bathalagoda Agro')
        ->and($products[1]->reference_cost)->toBe('192.0000')
        ->and($products[1]->reorder_level)->toBe('15.000')
        ->and($book->forProduct($products[1]->id)[$retail->id][$products[1]->base_unit_id])->toBe('290.00')
        ->and($book->forProduct($products[3]->id))->toBe([])
        ->and($products->pluck('short_code')->all())->toBe(['2000', '2001', '2002', '2003', '2004', '2005']);

    $warnings = $response->viewData('rowWarnings');

    expect($warnings[5])->toContain('No Selling Price: it is imported but cannot be billed until a price is set.')
        ->and($warnings[6])->toContain('Base unit g changed to packet: each 10g is counted as one packet.')
        ->and($warnings[7])->toContain('Base unit g changed to packet: each 50g is counted as one packet.');
});

test('a loose row gets both prices and a sealed bag in the same file opens into it', function () {
    previewAndImport(importFile([
        ['name' => 'Urea', 'category' => 'Fertilizers', 'base_unit' => 'bag', 'pack_size' => '50kg bag', 'retail_price' => '12000', 'wholesale_price' => '11800', 'opens_into' => 'Urea (loose)'],
        ['name' => 'Urea (loose)', 'category' => 'Fertilizers', 'base_unit' => 'kg', 'cost' => '200', 'loose_price_kg' => '250', 'loose_price_small' => '300'],
        ['name' => 'TSP (loose)', 'category' => 'Fertilizers', 'base_unit' => 'kg', 'loose_price_kg' => '280'],
    ]));

    $retail = PriceList::firstWhere('name', 'Retail');
    $kg = unitId('kg');
    $loose = Product::firstWhere('name', 'Urea (loose)');
    $bag = Product::firstWhere('name', 'Urea 50kg bag');
    $tsp = Product::firstWhere('name', 'TSP (loose)');
    $book = app(PriceBook::class);

    expect($loose->sold_loose)->toBeTrue()
        ->and($book->tiersForProduct($loose->id)[$retail->id][$kg])->toBe(['0.000' => '300.00', '1.000' => '250.00'])
        ->and($tsp->sold_loose)->toBeTrue()
        ->and($book->tiersForProduct($tsp->id)[$retail->id][$kg])->toBe(['0.000' => '280.00'])
        ->and($bag->sold_loose)->toBeFalse()
        ->and($bag->opens_into_product_id)->toBe($loose->id)
        ->and($bag->opens_into_qty)->toBe('50.000');
});

test('a sealed bag can open into a loose product that is already in the shop', function () {
    $loose = createProduct(['short_code' => '1100', 'name' => 'MOP (loose)', 'category' => 'Fertilizers', 'base_unit' => 'kg', 'sold_loose' => true]);

    previewAndImport(importFile([
        ['name' => 'MOP', 'category' => 'Fertilizers', 'base_unit' => 'bag', 'pack_size' => '25kg bag', 'retail_price' => '6500', 'opens_into' => '1100'],
    ]));

    $bag = Product::firstWhere('name', 'MOP 25kg bag');

    expect($bag->opens_into_product_id)->toBe($loose->id)
        ->and($bag->opens_into_qty)->toBe('25.000');
});

test('loose prices, packs and links that do not make sense are reported', function () {
    createProduct(['short_code' => '1200', 'name' => 'Dolomite 25kg bag', 'category' => 'Fertilizers', 'base_unit' => 'bag']);

    $response = $this->actingAs($this->owner)->post(route('catalog.products.import.preview'), [
        'file' => importFile([
            ['name' => 'Urea (loose)', 'category' => 'Fertilizers', 'base_unit' => 'kg', 'loose_price_kg' => '250', 'loose_price_small' => '30'],  // row 2: per 100 g
            ['name' => 'TSP', 'category' => 'Fertilizers', 'base_unit' => 'kg', 'pack_size' => '1kg packet', 'loose_price_kg' => '280'],         // row 3: pack and loose
            ['name' => 'Urea', 'category' => 'Fertilizers', 'base_unit' => 'bag', 'pack_size' => '50kg bag', 'opens_into' => 'Urea loose'],      // row 4: unknown
            ['name' => 'Dolomite', 'category' => 'Fertilizers', 'base_unit' => 'bag', 'pack_size' => '50kg bag', 'opens_into' => '1200'],        // row 5: not loose
            ['name' => 'MOP (loose)', 'category' => 'Fertilizers', 'base_unit' => 'packet', 'loose_price_kg' => '300'],                          // row 6: not weighable
            ['name' => 'Lime (loose)', 'category' => 'Fertilizers', 'base_unit' => 'kg', 'retail_price' => '90', 'loose_price_small' => '100'],  // row 7: two small prices
        ]),
    ])->assertOk();

    $errors = $response->viewData('rowErrors');

    expect(array_keys($errors))->toBe([2, 3, 4, 5, 6, 7])
        ->and(implode(' ', $errors[2]))->toContain('Write it as a price per kg')
        ->and(implode(' ', $errors[3]))->toContain('either a sealed pack')
        ->and(implode(' ', $errors[4]))->toContain('no loose product with that name or code')
        ->and(implode(' ', $errors[5]))->toContain('no loose product with that name or code')
        ->and(implode(' ', $errors[6]))->toContain('must allow decimals')
        ->and(implode(' ', $errors[7]))->toContain('Selling Price and Price for grams are different');
});

test('the export uses the import layout, with both loose prices', function () {
    $loose = createProduct(
        ['short_code' => '1100', 'name' => 'Urea (loose)', 'category' => 'Fertilizers', 'base_unit' => 'kg', 'sold_loose' => true],
        prices: ['Retail' => ['kg' => '300.00', 'kg@1' => '250.00'], 'Wholesale' => ['kg' => '240.00']],
    );
    createProduct(
        ['short_code' => '1101', 'name' => 'Urea 50kg bag', 'category' => 'Fertilizers', 'base_unit' => 'bag', 'opens_into_product_id' => $loose->id, 'opens_into_qty' => '50'],
        prices: ['Retail' => ['bag' => '12000.00']],
    );

    $export = new ProductsExport(Product::query()->orderBy('short_code'), withCost: true);
    $columns = count(ProductImportColumns::keys());
    $headings = array_slice($export->headings(), 0, $columns);
    $rows = array_map(fn (array $row) => array_combine($headings, array_slice($row, 0, $columns)), $export->array());

    expect($rows[0]["Price for Kg's (per kg)"])->toBe('250.00')
        ->and($rows[0]['Price for grams (per kg)'])->toBe('300.00')
        ->and($rows[0]['Selling Price'])->toBeNull()
        ->and($rows[0]['Whole Sale Price'])->toBe('240.00')
        ->and($rows[1]['Selling Price'])->toBe('12000.00')
        ->and($rows[1]['Opens Into'])->toBe('1100')
        ->and($rows[1]['Loose Qty Per Pack'])->toBe('50.000');
});
