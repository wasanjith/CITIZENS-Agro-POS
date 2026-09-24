<?php

use App\Domain\Catalog\Import\ProductImportColumns;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\OpeningStockEntry;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\PriceBook;
use App\Domain\Identity\Enums\Role;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
    $this->owner = userWithRole(Role::SuperAdmin);
});

/**
 * @param  list<array<string, mixed>>  $rows  keyed by template column
 */
function importFile(array $rows): UploadedFile
{
    $headings = ProductImportColumns::headings();
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $headings, escape: '');

    foreach ($rows as $row) {
        fputcsv($handle, array_map(fn ($column) => $row[$column] ?? '', $headings), escape: '');
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
        ->and($prices[$retail->id][$kgId])->toBe('180.00')
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
        ->and(implode(' ', $errors[7]))->toContain('Sale unit "bag=fifty"')->toContain('Retail price "abc"');

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
