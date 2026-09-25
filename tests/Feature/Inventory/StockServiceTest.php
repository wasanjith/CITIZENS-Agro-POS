<?php

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockService;
use App\Domain\System\Services\Settings;
use Database\Seeders\CatalogSeeder;

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
    $this->stock = app(StockService::class);
});

afterEach(function () {
    expectStockMatchesLedger();
});

test('products without batch tracking keep one default batch with a moving-average cost', function () {
    $product = createProduct(['base_unit' => 'kg']);

    $first = $this->stock->receive($product, null, '100', '160');
    $second = $this->stock->receive($product, null, '100', '180');

    expect($second->id)->toBe($first->id)
        ->and($second->isDefault())->toBeTrue()
        ->and($second->refresh()->unit_cost)->toBe('170.0000')
        ->and((string) $this->stock->available($product))->toBe('200.000')
        ->and(StockMovement::where('batch_id', $first->id)->count())->toBe(2);
});

test('batch-tracked products get a new batch per receipt', function () {
    $product = createProduct(['track_batches' => true, 'track_expiry' => true]);

    $a = $this->stock->receive($product, null, '10', '50', ['lot_no' => 'A1', 'expiry_date' => '2027-01-31']);
    $b = $this->stock->receive($product, null, '10', '55', ['lot_no' => 'B1', 'expiry_date' => '2027-03-31']);

    expect($a->id)->not->toBe($b->id)
        ->and($a->lot_no)->toBe('A1')
        ->and($b->unit_cost)->toBe('55.0000')
        ->and(Batch::where('product_id', $product->id)->count())->toBe(2);
});

test('issue allocates first-expiry-first-out across three batches and consumes part of one', function () {
    $product = createProduct(['track_batches' => true, 'track_expiry' => true]);

    $late = $this->stock->receive($product, null, '10', '30', ['lot_no' => 'LATE', 'expiry_date' => '2027-12-31']);
    $early = $this->stock->receive($product, null, '4', '10', ['lot_no' => 'EARLY', 'expiry_date' => '2027-01-31']);
    $middle = $this->stock->receive($product, null, '5', '20', ['lot_no' => 'MID', 'expiry_date' => '2027-06-30']);

    $allocations = $this->stock->issue($product, null, '12', MovementType::Sale);

    expect(array_map(fn ($allocation) => [$allocation->batchId, $allocation->qty, $allocation->unitCost], $allocations))->toBe([
        [$early->id, '4.000', '10.0000'],
        [$middle->id, '5.000', '20.0000'],
        [$late->id, '3.000', '30.0000'],
    ]);

    expect(StockLevel::where('batch_id', $early->id)->value('qty_on_hand'))->toBe('0.000')
        ->and(StockLevel::where('batch_id', $middle->id)->value('qty_on_hand'))->toBe('0.000')
        ->and(StockLevel::where('batch_id', $late->id)->value('qty_on_hand'))->toBe('7.000')
        ->and(StockMovement::where('type', MovementType::Sale)->sum('qty'))->toEqual('-12.000');
});

test('batches without an expiry date come after dated ones, oldest first', function () {
    $product = createProduct(['track_batches' => true]);

    $undated = $this->stock->receive($product, null, '5', '10', ['lot_no' => 'U1']);
    $dated = $this->stock->receive($product, null, '5', '10', ['lot_no' => 'D1', 'expiry_date' => '2030-01-01']);

    $allocations = $this->stock->issue($product, null, '6', MovementType::Sale);

    expect($allocations[0]->batchId)->toBe($dated->id)
        ->and($allocations[1]->batchId)->toBe($undated->id)
        ->and($allocations[1]->qty)->toBe('1.000');
});

test('issuing more than the stock throws and changes nothing', function () {
    $product = createProduct();
    $this->stock->receive($product, null, '5', '100');

    expect(fn () => $this->stock->issue($product, null, '6', MovementType::Sale))
        ->toThrow(InsufficientStockException::class, '6 needed, only 5 available');

    expect((string) $this->stock->available($product))->toBe('5.000')
        ->and(StockMovement::where('type', MovementType::Sale)->count())->toBe(0);
});

test('with negative stock allowed the shortfall comes out of the default batch', function () {
    app(Settings::class)->setGroup('inventory', ['allow_negative_stock' => true]);
    $product = createProduct();
    $this->stock->receive($product, null, '5', '100');

    $allocations = $this->stock->issue($product, null, '8', MovementType::Sale);

    expect($allocations)->toHaveCount(1)
        ->and($allocations[0]->qty)->toBe('8.000')
        ->and((string) $this->stock->available($product))->toBe('-3.000');
});

test('reserved stock cannot be issued until it is released', function () {
    $product = createProduct();
    $this->stock->receive($product, null, '10', '100');

    $reserved = $this->stock->reserve($product, null, '7');

    expect((string) $this->stock->available($product))->toBe('3.000')
        ->and(fn () => $this->stock->issue($product, null, '4', MovementType::Sale))->toThrow(InsufficientStockException::class);

    $this->stock->release($reserved);

    expect((string) $this->stock->available($product))->toBe('10.000')
        ->and(StockLevel::where('product_id', $product->id)->value('qty_reserved'))->toBe('0.000');
});

test('variants have their own stock', function () {
    $product = createProduct(variants: [
        ['short_code' => 'V26', 'name' => '26"', 'is_active' => true],
        ['short_code' => 'V28', 'name' => '28"', 'is_active' => true],
    ]);
    [$v26, $v28] = $product->variants()->orderBy('short_code')->get()->all();

    $this->stock->receive($product, $v26->id, '4', '100');
    $this->stock->receive($product, $v28->id, '9', '100');

    expect((string) $this->stock->available($product, $v26->id))->toBe('4.000')
        ->and((string) $this->stock->available($product, $v28->id))->toBe('9.000')
        ->and((string) $this->stock->available($product))->toBe('0.000')
        ->and(fn () => $this->stock->issue($product, $v26->id, '5', MovementType::Sale))->toThrow(InsufficientStockException::class);
});

test('adjust adds to the default batch and removes from a chosen batch', function () {
    $product = createProduct(['track_batches' => true]);
    $batch = $this->stock->receive($product, null, '10', '40', ['lot_no' => 'X1']);

    $this->stock->adjust($product, null, null, '3', MovementType::AdjustIn, unitCost: '45');
    $this->stock->adjust($product, null, $batch, '-4', MovementType::Damage);

    $default = Batch::firstWhere('default_key', Batch::defaultKey($product->id, null));

    expect($default->unit_cost)->toBe('45.0000')
        ->and(StockLevel::where('batch_id', $default->id)->value('qty_on_hand'))->toBe('3.000')
        ->and(StockLevel::where('batch_id', $batch->id)->value('qty_on_hand'))->toBe('6.000')
        ->and(StockMovement::where('type', MovementType::Damage)->value('qty'))->toBe('-4.000');
});

test('stock movements are append-only', function () {
    $product = createProduct();
    $this->stock->receive($product, null, '1', '1');

    expect(fn () => StockMovement::first()->update(['qty' => 5]))->toThrow(LogicException::class)
        ->and(fn () => StockMovement::first()->delete())->toThrow(LogicException::class);
});
