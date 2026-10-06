<?php

use App\Domain\Catalog\Actions\SaveProductAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Catalog\Services\PriceBook;
use App\Domain\Catalog\Services\ProductSearchService;
use App\Domain\Identity\Enums\Role;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Sales\Services\CartPricer;
use App\Domain\Sales\Support\InvoicePresenter;
use Database\Seeders\CatalogSeeder;
use Illuminate\Validation\ValidationException;

/*
 * Real-shop pricing: loose goods have a rate for small amounts (under 1 kg) and a
 * cheaper rate from 1 kg; every price is optional and a wholesale customer pays the
 * retail price for anything without a wholesale price.
 */

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->retail = PriceList::firstWhere('name', 'Retail');
    $this->wholesale = PriceList::firstWhere('name', 'Wholesale');
    $this->loose = createProduct(
        ['name' => 'Urea (loose)', 'category' => 'Fertilizers', 'base_unit' => 'kg', 'sold_loose' => true],
        prices: ['Retail' => ['kg' => '300.00', 'kg@1' => '250.00'], 'Wholesale' => ['kg@1' => '240.00']],
    );
});

/**
 * Price one line through the counter pricer and return it.
 *
 * @return array<string, mixed>
 */
function priceOneLine(Product $product, string $unit, string $qty, ?PriceList $list = null): array
{
    $cart = cartPayload([['product_id' => $product->id, 'unit_id' => unitId($unit), 'qty' => $qty]], $list ? ['price_list_id' => $list->id] : []);

    return app(CartPricer::class)->price($cart, test()->owner)['lines'][0];
}

test('a loose product uses the small-amount rate under 1 kg and the kg rate from 1 kg', function (string $qty, string $rate, string $tier, string $gross) {
    $line = priceOneLine($this->loose, 'kg', $qty);

    expect($line['unit_price'])->toBe($rate)
        ->and($line['price_tier'])->toBe($tier)
        ->and($line['gross'])->toBe($gross)
        ->and($line['price_list_id'])->toBe($this->retail->id);
})->with([
    '250 g' => ['0.250', '300.00', '0.000', '75.00'],
    '999 g' => ['0.999', '300.00', '0.000', '299.70'],
    '1 kg' => ['1', '250.00', '1.000', '250.00'],
    '1.5 kg' => ['1.5', '250.00', '1.000', '375.00'],
]);

test('a wholesale customer gets the wholesale price where there is one, else the retail price', function () {
    $small = priceOneLine($this->loose, 'kg', '0.5', $this->wholesale);
    $large = priceOneLine($this->loose, 'kg', '2', $this->wholesale);

    expect($small['unit_price'])->toBe('300.00')
        ->and($small['price_list_id'])->toBe($this->retail->id)
        ->and($large['unit_price'])->toBe('240.00')
        ->and($large['price_list_id'])->toBe($this->wholesale->id);
});

test('a sealed product ignores quantity tiers', function () {
    $packet = createProduct(['name' => 'Okra 10g packet', 'category' => 'Seeds', 'base_unit' => 'packet'], prices: ['Retail' => ['packet' => '120.00']]);
    ProductPrice::create([
        'product_id' => $packet->id,
        'unit_id' => unitId('packet'),
        'min_qty' => '5',
        'price_list_id' => $this->retail->id,
        'price' => '100.00',
        'effective_from' => now()->subMinute(),
    ]);

    expect(priceOneLine($packet, 'packet', '10')['unit_price'])->toBe('120.00');
});

test('a product without any price cannot be billed', function () {
    $packet = createProduct(['name' => 'Okra 250g packet', 'category' => 'Seeds', 'base_unit' => 'packet']);

    expect(fn () => priceOneLine($packet, 'packet', '1'))
        ->toThrow(ValidationException::class, 'Okra 250g packet has no price per packet.');
});

test('clearing a price removes it, and the wholesale customer falls back to the retail price', function () {
    $packet = createProduct(
        ['name' => 'Okra 50g packet', 'category' => 'Seeds', 'base_unit' => 'packet'],
        prices: ['Retail' => ['packet' => '290.00'], 'Wholesale' => ['packet' => '270.00']],
    );

    expect(priceOneLine($packet, 'packet', '1', $this->wholesale)['unit_price'])->toBe('270.00');

    app(SaveProductAction::class)->handle([
        'prices' => [['unit_id' => unitId('packet'), 'price_list_id' => $this->wholesale->id, 'min_qty' => '0', 'price' => '']],
    ], $this->owner, $packet);

    $line = priceOneLine($packet, 'packet', '1', $this->wholesale);

    expect($line['unit_price'])->toBe('290.00')
        ->and($line['price_list_id'])->toBe($this->retail->id)
        ->and(app(PriceBook::class)->forProduct($packet->id))->not->toHaveKey($this->wholesale->id)
        ->and(ProductPrice::where('product_id', $packet->id)->latest('id')->value('price'))->toBeNull();

    $this->actingAs($this->owner)->get(route('catalog.products.show', $packet))
        ->assertOk()
        ->assertSee('Removed');
});

test('an empty price that was never set adds no history row', function () {
    $packet = createProduct(['name' => 'Okra 100g packet', 'category' => 'Seeds', 'base_unit' => 'packet'], prices: ['Retail' => ['packet' => '530.00']]);
    $before = ProductPrice::count();

    app(SaveProductAction::class)->handle([
        'prices' => [['unit_id' => unitId('packet'), 'price_list_id' => $this->wholesale->id, 'min_qty' => '0', 'price' => '']],
    ], $this->owner, $packet);

    expect(ProductPrice::count())->toBe($before);
});

test('quantity prices are only allowed on products sold loose', function () {
    $this->actingAs($this->owner)->post(route('catalog.products.store'), [
        'short_code' => '1500',
        'name' => 'TSP 50kg bag',
        'category_id' => Category::firstWhere('name', 'Fertilizers')->id,
        'base_unit_id' => unitId('bag'),
        'reorder_level' => '0',
        'reorder_qty' => '0',
        'units' => [['unit_id' => unitId('bag'), 'factor' => '1', 'is_default_sale' => '1', 'is_default_purchase' => '1']],
        'prices' => [['unit_id' => unitId('bag'), 'price_list_id' => $this->retail->id, 'min_qty' => '5', 'price' => '9500']],
    ])->assertSessionHasErrors('prices.0.min_qty');
});

test('the minimum margin also guards quantity prices', function () {
    $product = createProduct(['name' => 'MOP (loose)', 'category' => 'Fertilizers', 'base_unit' => 'kg', 'sold_loose' => true, 'reference_cost' => '200', 'min_selling_margin_pct' => '10']);

    // Cost 200/kg + 10% = 220/kg.
    expect(fn () => app(SaveProductAction::class)->handle([
        'prices' => [['unit_id' => unitId('kg'), 'price_list_id' => $this->retail->id, 'min_qty' => '1', 'price' => '210']],
    ], $this->owner, $product))->toThrow(ValidationException::class, 'below the minimum of Rs. 220.00');
});

test('a sealed pack can only be opened into a product sold loose', function () {
    $sealedTarget = createProduct(['name' => 'Urea 25kg bag', 'category' => 'Fertilizers', 'base_unit' => 'bag']);
    $payload = fn (array $overrides) => [
        'short_code' => '1600',
        'name' => 'Urea 50kg bag',
        'category_id' => Category::firstWhere('name', 'Fertilizers')->id,
        'base_unit_id' => unitId('bag'),
        'reorder_level' => '0',
        'reorder_qty' => '0',
        'units' => [['unit_id' => unitId('bag'), 'factor' => '1', 'is_default_sale' => '1', 'is_default_purchase' => '1']],
        'opens_into_qty' => '50',
        ...$overrides,
    ];

    $this->actingAs($this->owner)
        ->post(route('catalog.products.store'), $payload(['opens_into_product_id' => $sealedTarget->id]))
        ->assertSessionHasErrors('opens_into_product_id');

    $this->actingAs($this->owner)
        ->post(route('catalog.products.store'), $payload(['opens_into_product_id' => $this->loose->id, 'sold_loose' => '1']))
        ->assertSessionHasErrors('opens_into_product_id');

    $this->actingAs($this->owner)
        ->post(route('catalog.products.store'), $payload(['opens_into_product_id' => $this->loose->id, 'opens_into_qty' => '']))
        ->assertSessionHasErrors('opens_into_qty');

    $this->actingAs($this->owner)
        ->post(route('catalog.products.store'), $payload(['opens_into_product_id' => $this->loose->id]))
        ->assertSessionHasNoErrors();

    $bag = Product::firstWhere('short_code', '1600');

    expect($bag->opens_into_product_id)->toBe($this->loose->id)
        ->and($bag->opens_into_qty)->toBe('50.000')
        ->and($bag->sold_loose)->toBeFalse();
});

test('the invoice line records the price list its price came from', function () {
    $pos = posSetup();
    $packet = createProduct(['name' => 'Okra 10g packet', 'category' => 'Seeds', 'base_unit' => 'packet'], prices: ['Retail' => ['packet' => '120.00']]);
    app(StockService::class)->receive($this->loose, null, '100', '200');
    app(StockService::class)->receive($packet, null, '20', '72');

    $sale = counterInvoice($pos, [
        ['product_id' => $this->loose->id, 'unit_id' => unitId('kg'), 'qty' => '2'],
        ['product_id' => $packet->id, 'unit_id' => unitId('packet'), 'qty' => '1'],
    ], ['price_list_id' => $this->wholesale->id, 'tendered' => '1000']);

    $items = $sale->items()->orderBy('line_no')->get();

    expect($items[0]->unit_price)->toBe('240.00')
        ->and($items[0]->price_list_id)->toBe($this->wholesale->id)
        ->and($items[0]->line_total)->toBe('480.00')
        ->and($items[1]->unit_price)->toBe('120.00')
        ->and($items[1]->price_list_id)->toBe($this->retail->id);
});

test('the product form saves both loose prices and shows them again on edit', function () {
    $payload = [
        'short_code' => '1700',
        'name' => 'TSP (loose)',
        'category_id' => Category::firstWhere('name', 'Fertilizers')->id,
        'base_unit_id' => unitId('kg'),
        'sold_loose' => '1',
        'reorder_level' => '0',
        'reorder_qty' => '0',
        'units' => [['unit_id' => unitId('kg'), 'factor' => '1', 'is_default_sale' => '1', 'is_default_purchase' => '1']],
        'prices' => [
            ['unit_id' => unitId('kg'), 'price_list_id' => $this->retail->id, 'min_qty' => '0', 'price' => '320'],
            ['unit_id' => unitId('kg'), 'price_list_id' => $this->retail->id, 'min_qty' => '1', 'price' => '280'],
            ['unit_id' => unitId('kg'), 'price_list_id' => $this->wholesale->id, 'min_qty' => '0', 'price' => ''],
            ['unit_id' => unitId('kg'), 'price_list_id' => $this->wholesale->id, 'min_qty' => '1', 'price' => '270'],
        ],
    ];

    $this->actingAs($this->owner)->post(route('catalog.products.store'), $payload)->assertSessionHasNoErrors();

    $product = Product::firstWhere('short_code', '1700');

    expect(app(PriceBook::class)->tiersForProduct($product->id))->toBe([
        $this->retail->id => [unitId('kg') => ['0.000' => '320.00', '1.000' => '280.00']],
        $this->wholesale->id => [unitId('kg') => ['1.000' => '270.00']],
    ]);

    $this->actingAs($this->owner)->get(route('catalog.products.edit', $product))
        ->assertOk()
        ->assertSee($this->retail->id.':'.unitId('kg').'@1', false)
        ->assertSee('soldLoose', false);
});

test('a product that is no longer sold loose loses its quantity prices', function () {
    app(SaveProductAction::class)->handle([
        'sold_loose' => false,
        'prices' => [['unit_id' => unitId('kg'), 'price_list_id' => $this->retail->id, 'min_qty' => '0', 'price' => '300.00']],
    ], $this->owner, $this->loose);

    expect(app(PriceBook::class)->tiersForProduct($this->loose->id))->toBe([
        $this->retail->id => [unitId('kg') => ['0.000' => '300.00']],
    ])
        ->and(priceOneLine($this->loose->refresh(), 'kg', '2')['unit_price'])->toBe('300.00');
});

test('an empty quantity price on a product that is not loose is not an error', function () {
    $this->actingAs($this->owner)->post(route('catalog.products.store'), [
        'short_code' => '2100',
        'name' => 'Okra 10g packet',
        'category_id' => Category::firstWhere('name', 'Seeds')->id,
        'base_unit_id' => unitId('packet'),
        'reorder_level' => '0',
        'reorder_qty' => '0',
        'units' => [['unit_id' => unitId('packet'), 'factor' => '1', 'is_default_sale' => '1', 'is_default_purchase' => '1']],
        'prices' => [
            ['unit_id' => unitId('packet'), 'price_list_id' => $this->retail->id, 'min_qty' => '0', 'price' => '120'],
            ['unit_id' => unitId('packet'), 'price_list_id' => $this->retail->id, 'min_qty' => '1', 'price' => ''],
        ],
    ])->assertSessionHasNoErrors();
});

test('the counter gets every rate of a unit, with the retail fallback filled in', function () {
    $line = priceOneLine($this->loose, 'kg', '0.5', $this->wholesale);

    expect($line['units'][0]['tiers'])->toBe([
        ['min_qty' => '0.000', 'price' => '300.00'],
        ['min_qty' => '1.000', 'price' => '240.00'],
    ]);

    $this->actingAs($this->owner)
        ->getJson(route('api.pos.search', ['q' => $this->loose->short_code, 'price_list_id' => $this->retail->id]))
        ->assertOk()
        ->assertJsonPath('items.0.sold_loose', true)
        ->assertJsonPath('items.0.unit.tiers', [
            ['min_qty' => '0.000', 'price' => '300.00'],
            ['min_qty' => '1.000', 'price' => '250.00'],
        ]);
});

test('a sealed product has a single rate at the counter', function () {
    $packet = createProduct(['name' => 'Okra 50g packet', 'category' => 'Seeds', 'base_unit' => 'packet'], prices: ['Retail' => ['packet' => '290.00']]);

    expect(priceOneLine($packet, 'packet', '3')['units'][0]['tiers'])->toBe([['min_qty' => '0.000', 'price' => '290.00']]);
});

test('grams can be typed in the search box', function (string $query, ?string $qty, string $term) {
    expect(ProductSearchService::parseQuantity($query))->toBe([$qty, $term]);
})->with([
    'grams' => ['750g*urea', '0.75', 'urea'],
    'grams with spaces' => ['250 g * urea', '0.25', 'urea'],
    'kilograms' => ['2kg*urea', '2', 'urea'],
    'plain quantity' => ['5*urea', '5', 'urea'],
    'no quantity' => ['urea', null, 'urea'],
]);

test('the invoice shows grams next to an amount under 1 kg', function () {
    $pos = posSetup();
    app(StockService::class)->receive($this->loose, null, '10', '200');

    $sale = counterInvoice($pos, [['product_id' => $this->loose->id, 'unit_id' => unitId('kg'), 'qty' => '0.75']], ['tendered' => '500']);
    $html = view('print.partials.invoice-body', app(InvoicePresenter::class)->present($sale, 'en', false))->render();

    expect($sale->items()->value('line_total'))->toBe('225.00')
        ->and($html)->toContain('0.75 kg (750 g) × 300.00');
});
