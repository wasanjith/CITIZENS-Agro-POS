<?php

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Two counters sell the last 5 units at the same moment. Counter A's transaction
| holds the stock rows; counter B must wait (here: time out) instead of reading the
| stale 5, and once A has committed B finds nothing left.
*/

beforeEach(function () {
    config(['database.connections.counter_b' => config('database.connections.mysql')]);
});

afterEach(function () {
    DB::setDefaultConnection('mysql');
    DB::purge('counter_b');
});

test('two counters issuing the last 5 units: one succeeds, the other gets no stock', function () {
    $product = createProduct();
    app(StockService::class)->receive($product, null, '5', '100');

    // Counter A takes the last 5 units but has not committed yet.
    DB::connection('mysql')->beginTransaction();
    app(StockService::class)->issue($product, null, '5', MovementType::Sale);

    // Counter B tries at the same time and is blocked by A's row lock.
    DB::setDefaultConnection('counter_b');
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    expect(fn () => app(StockService::class)->issue($product, null, '5', MovementType::Sale))
        ->toThrow(QueryException::class, 'Lock wait timeout');

    // A commits; B tries again and finds no stock left.
    DB::setDefaultConnection('mysql');
    DB::connection('mysql')->commit();
    DB::setDefaultConnection('counter_b');

    expect(fn () => app(StockService::class)->issue($product, null, '5', MovementType::Sale))
        ->toThrow(InsufficientStockException::class);

    DB::setDefaultConnection('mysql');

    expect(StockLevel::where('product_id', $product->id)->value('qty_on_hand'))->toBe('0.000');
    expectStockMatchesLedger();
});
