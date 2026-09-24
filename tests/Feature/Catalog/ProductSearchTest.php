<?php

use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Services\ProductSearchService;
use App\Domain\Identity\Enums\Role;
use Database\Seeders\CatalogSeeder;
use Laravel\Scout\EngineManager;
use Meilisearch\Client;

/*
| These run on the MySQL search path (SCOUT_DRIVER=null in phpunit.xml). Rows are not
| committed here, so they exercise the exact-code and LIKE parts of the fallback;
| tests/Search covers FULLTEXT and Meilisearch with committed data.
*/

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
    $this->staff = userWithRole(Role::SalesStaff);
    $this->urea = createUrea();
    createProduct(['short_code' => '10010', 'name' => 'Urea sprayer nozzle', 'category' => 'Tools']);
});

test('an exact short code returns only that product', function () {
    $this->actingAs($this->staff)
        ->getJson(route('api.pos.search', ['q' => '1001']))
        ->assertOk()
        ->assertJsonPath('engine', ProductSearchService::ENGINE_CODE)
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.short_code', '1001')
        ->assertJsonPath('items.0.price', '9000.00')
        ->assertJsonPath('items.0.unit.name', 'bag');
});

test('a variant code returns that variant', function () {
    createProduct(['short_code' => '5001', 'name' => 'Bicycle Tyre', 'category' => 'Tyres'], variants: [
        ['short_code' => '5002', 'name' => '26"', 'is_active' => true],
        ['short_code' => '5003', 'name' => '28"', 'is_active' => true],
    ]);

    $this->actingAs($this->staff)
        ->getJson(route('api.pos.search', ['q' => '5003']))
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.short_code', '5003')
        ->assertJsonPath('items.0.name', 'Bicycle Tyre 28"');
});

test('qty*term sets the quantity', function (string $query, string $qty) {
    $this->actingAs($this->staff)
        ->getJson(route('api.pos.search', ['q' => $query]))
        ->assertJsonPath('qty', $qty)
        ->assertJsonPath('term', 'urea')
        ->assertJsonPath('items.0.short_code', '1001');
})->with([
    ['5*urea', '5'],
    ['2.5 * urea', '2.5'],
]);

test('the quantity parser leaves plain searches alone', function () {
    expect(ProductSearchService::parseQuantity('urea'))->toBe([null, 'urea'])
        ->and(ProductSearchService::parseQuantity('0*urea'))->toBe([null, '0*urea'])
        ->and(ProductSearchService::parseQuantity(' 12*tsp 1kg '))->toBe(['12', 'tsp 1kg']);
});

test('aliases and Sinhala names find the product', function (string $query) {
    $this->actingAs($this->staff)
        ->getJson(route('api.pos.search', ['q' => $query]))
        ->assertJsonPath('items.0.short_code', '1001');
})->with(['yuriya', 'u50', 'යූරියා']);

test('inactive products are not found', function () {
    $this->urea->update(['is_active' => false]);

    $response = $this->actingAs($this->staff)
        ->getJson(route('api.pos.search', ['q' => '1001']))
        ->assertJsonPath('engine', ProductSearchService::ENGINE_MYSQL);

    // Only the active 10010 (prefix match) is left.
    expect(array_column($response->json('items'), 'short_code'))->toBe(['10010']);
});

test('sales staff never receive cost fields', function () {
    $response = $this->actingAs($this->staff)->getJson(route('api.pos.search', ['q' => 'urea']))->assertOk();

    expect($response->json('items'))->not->toBeEmpty();
    foreach ($response->json('items') as $item) {
        expect($item)->not->toHaveKeys(['reference_cost', 'min_selling_margin_pct']);
    }
    expect($response->getContent())->not->toContain('160.0000');
});

test('the manager receives cost fields', function () {
    $this->actingAs(userWithRole(Role::Manager))
        ->getJson(route('api.pos.search', ['q' => '1001']))
        ->assertJsonPath('items.0.reference_cost', '160.0000');
});

test('prices come from the requested price list', function () {
    $wholesale = PriceList::firstWhere('name', 'Wholesale');

    $this->actingAs($this->staff)
        ->getJson(route('api.pos.search', ['q' => '1001', 'price_list_id' => $wholesale->id]))
        ->assertJsonPath('items.0.price', '8800.00');
});

test('search falls back to MySQL when Meilisearch is unreachable', function () {
    config([
        'scout.driver' => 'meilisearch',
        'scout.meilisearch.host' => 'http://127.0.0.1:1',
    ]);
    app()->forgetInstance(Client::class);
    app()->forgetInstance(EngineManager::class);

    $this->actingAs($this->staff)
        ->getJson(route('api.pos.search', ['q' => 'yuriya']))
        ->assertOk()
        ->assertJsonPath('engine', ProductSearchService::ENGINE_MYSQL)
        ->assertJsonPath('items.0.short_code', '1001');
});

test('the search endpoint needs a signed-in user and is throttled', function () {
    $this->getJson(route('api.pos.search', ['q' => 'urea']))->assertUnauthorized();

    $route = app('router')->getRoutes()->getByName('api.pos.search');
    expect($route->gatherMiddleware())->toContain('throttle:120,1');
});
