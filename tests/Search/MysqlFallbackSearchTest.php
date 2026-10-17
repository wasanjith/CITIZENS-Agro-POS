<?php

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Services\ProductSearchService;

/*
| MySQL FULLTEXT (ngram) fallback with committed rows: what the shop gets while
| Meilisearch is down.
*/

beforeEach(function () {
    config(['scout.driver' => 'null']);
    createUrea();
    createProduct(['short_code' => '1002', 'name' => 'Triple Super Phosphate (TSP) 50kg', 'name_si' => 'ටී.එස්.පී.', 'aliases' => 'tsp', 'category' => 'Fertilizers']);
    createProduct(['short_code' => '4001', 'name' => 'Mammoty', 'name_si' => 'උදැල්ල', 'aliases' => 'udalla, hoe', 'category' => 'Tools']);
    $this->search = app(ProductSearchService::class);
});

test('the fallback finds products by name, Sinhala name, alias and typo', function (string $query, string $code) {
    $result = $this->search->search($query);

    expect($result['engine'])->toBe(ProductSearchService::ENGINE_MYSQL)
        ->and($result['items'][0]['short_code'] ?? null)->toBe($code);
})->with([
    'name' => ['urea', '1001'],
    'typo' => ['ureea', '1001'],
    'Sinhala' => ['යූරියා', '1001'],
    'Sinhala part' => ['උදැල්', '4001'],
    'alias' => ['yuriya', '1001'],
    'short alias' => ['tsp', '1002'],
]);

test('the fallback finds a product by brand, and brand + name puts that brand first', function () {
    $baur = Brand::create(['name' => 'Baur']);
    createProduct(['short_code' => '1005', 'name' => 'Urea 50kg', 'brand_id' => $baur->id, 'category' => 'Fertilizers']);

    expect($this->search->search('baur')['items'][0]['short_code'] ?? null)->toBe('1005')
        ->and($this->search->search('baur urea')['items'][0]['short_code'] ?? null)->toBe('1005')
        ->and($this->search->search('baur urea')['brands'][0]['name'] ?? null)->toBe('Baur');
});
