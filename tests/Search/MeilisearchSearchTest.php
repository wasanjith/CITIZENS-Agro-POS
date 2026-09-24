<?php

use App\Domain\Catalog\Jobs\SyncSearchSynonymsJob;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SearchSynonym;
use App\Domain\Catalog\Services\ProductSearchService;
use Illuminate\Support\Facades\Artisan;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;

/*
| Real Meilisearch (docker-compose.dev.yml) with a "testing_" index prefix.
| Skipped when Meilisearch is not running.
*/

function waitForMeilisearch(): void
{
    $client = app(Client::class);
    $pending = $client->getTasks((new TasksQuery)->setStatuses(['enqueued', 'processing']));

    foreach ($pending->getResults() as $task) {
        $client->waitForTask($task['uid'], 10000);
    }
}

beforeEach(function () {
    config(['scout.driver' => 'meilisearch', 'scout.prefix' => 'testing_']);

    try {
        app(Client::class)->health();
    } catch (Throwable) {
        $this->markTestSkipped('Meilisearch is not running (docker compose -f docker-compose.dev.yml up -d).');
    }

    Artisan::call('scout:sync-index-settings');
    app(Client::class)->index((new Product)->searchableAs())->deleteAllDocuments();
    waitForMeilisearch();

    createUrea();
    createProduct(['short_code' => '1002', 'name' => 'Triple Super Phosphate (TSP) 50kg', 'name_si' => 'ටී.එස්.පී.', 'category' => 'Fertilizers']);
    createProduct(['short_code' => '1003', 'name' => 'Muriate of Potash (MOP) 50kg', 'name_si' => 'එම්.ඕ.පී.', 'aliases' => 'mop', 'category' => 'Fertilizers']);
    createProduct(['short_code' => '5001', 'name' => 'Bicycle Tyre', 'aliases' => 'tyre', 'category' => 'Tyres'], variants: [
        ['short_code' => '5002', 'name' => '26"', 'is_active' => true],
        ['short_code' => '5003', 'name' => '28"', 'is_active' => true],
    ]);
    createProduct(['short_code' => '9001', 'name' => 'Old urea stock', 'category' => 'Other', 'is_active' => false]);

    SearchSynonym::create(['term' => 'potash', 'synonyms' => ['pottasiyam']]);
    SyncSearchSynonymsJob::dispatchSync();
    waitForMeilisearch();

    $this->search = app(ProductSearchService::class);
});

test('meilisearch finds products by typo, Sinhala, alias and synonym', function (string $query, string $code) {
    $result = $this->search->search($query);

    expect($result['engine'])->toBe(ProductSearchService::ENGINE_MEILISEARCH)
        ->and($result['items'][0]['short_code'] ?? null)->toBe($code);
})->with([
    'typo' => ['ureea', '1001'],
    'Sinhala' => ['යූරියා', '1001'],
    'alias' => ['yuriya', '1001'],
    'synonym' => ['pottasiyam', '1003'],
    'size of a variant' => ['tyre 28', '5003'],
]);

test('an exact short code wins over text matches', function () {
    $result = $this->search->search('1002');

    expect($result['engine'])->toBe(ProductSearchService::ENGINE_CODE)
        ->and(array_column($result['items'], 'short_code'))->toBe(['1002']);
});

test('5*urea returns urea with quantity 5', function () {
    $result = $this->search->search('5*urea');

    expect($result['qty'])->toBe('5')
        ->and($result['items'][0]['short_code'])->toBe('1001');
});

test('inactive products are left out', function () {
    $codes = array_column($this->search->search('urea')['items'], 'short_code');

    expect($codes)->toContain('1001')->not->toContain('9001');
});

test('fast sellers rank first among equal matches', function () {
    createProduct(['short_code' => '4001', 'name' => 'Knapsack Sprayer 16L', 'category' => 'Tools']);
    $fast = createProduct(['short_code' => '4002', 'name' => 'Knapsack Sprayer 20L', 'category' => 'Tools']);
    Product::whereKey($fast->id)->update(['sales_velocity_30d' => 50]);
    $fast->refresh()->searchable();
    waitForMeilisearch();

    expect($this->search->search('knapsack sprayer')['items'][0]['short_code'])->toBe('4002');
});
