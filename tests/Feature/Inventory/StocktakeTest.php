<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Identity\Enums\Role;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Enums\StocktakeStatus;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Stocktake;
use App\Domain\Inventory\Services\StockService;
use App\Domain\System\Services\Settings;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DocumentSequenceSeeder;

beforeEach(function () {
    $this->seed([CatalogSeeder::class, DocumentSequenceSeeder::class]);
    app(Settings::class)->setGroup('inventory', ['adjustment_approval_limit' => 10000]);
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->manager = userWithRole(Role::Manager);
    $this->stock = app(StockService::class);

    $this->urea = createUrea();
    $this->seeds = createProduct(['name' => 'Chilli seeds', 'category' => 'Seeds', 'track_batches' => true, 'base_unit' => 'packet']);
    $this->empty = createProduct(['name' => 'Onion seeds', 'category' => 'Seeds', 'base_unit' => 'packet', 'reference_cost' => '100']);

    $this->stock->receive($this->urea, null, '500', '170');
    $this->early = $this->stock->receive($this->seeds, null, '20', '250', ['lot_no' => 'A', 'expiry_date' => '2027-01-31']);
    $this->late = $this->stock->receive($this->seeds, null, '30', '260', ['lot_no' => 'B', 'expiry_date' => '2027-06-30']);
});

afterEach(function () {
    expectStockMatchesLedger();
});

function startStocktake(array $categories = []): Stocktake
{
    test()->actingAs(test()->manager)
        ->post(route('inventory.stocktakes.store'), ['categories' => $categories])
        ->assertSessionHasNoErrors();

    return Stocktake::latest('id')->firstOrFail();
}

test('starting freezes one line per batch in scope, plus products without stock', function () {
    $stocktake = startStocktake([Category::firstWhere('name', 'Seeds')->id]);

    $lines = $stocktake->lines()->orderBy('id')->get();

    expect($stocktake->status)->toBe(StocktakeStatus::Counting)
        ->and($stocktake->number)->toStartWith('STK-')
        ->and($lines)->toHaveCount(3)
        ->and($lines->pluck('product_id')->unique()->sort()->values()->all())->toBe(collect([$this->seeds->id, $this->empty->id])->sort()->values()->all())
        ->and($lines->firstWhere('batch_id', $this->early->id)->system_qty)->toBe('20.000')
        ->and($lines->firstWhere('product_id', $this->empty->id)->batch_id)->toBeNull();
});

test('count, review and post: stock ends up equal to the count and uncounted lines stay', function () {
    $stocktake = startStocktake();
    $lines = $stocktake->lines()->get();

    $counts = [
        $lines->firstWhere('batch_id', $this->early->id)->id => '18',   // 2 missing
        $lines->firstWhere('batch_id', $this->late->id)->id => '30',    // matches
        $lines->firstWhere('product_id', $this->empty->id)->id => '4',  // found on the shelf
        // urea not counted
    ];

    $this->actingAs($this->manager)
        ->putJson(route('inventory.stocktakes.counts', $stocktake), ['counts' => $counts])
        ->assertOk()
        ->assertJson(['saved' => 3]);

    $this->actingAs($this->manager)->post(route('inventory.stocktakes.finish', $stocktake))->assertRedirect();
    expect($stocktake->refresh()->status)->toBe(StocktakeStatus::Review);

    $this->actingAs($this->manager)->get(route('inventory.stocktakes.show', $stocktake))->assertOk()->assertSee('Onion seeds');

    $this->actingAs($this->manager)->post(route('inventory.stocktakes.post', $stocktake))->assertSessionHasNoErrors();

    expect($stocktake->refresh()->status)->toBe(StocktakeStatus::Posted)
        ->and((string) $this->stock->available($this->seeds))->toBe('48.000')
        ->and((string) $this->stock->available($this->empty))->toBe('4.000')
        ->and((string) $this->stock->available($this->urea))->toBe('500.000')
        ->and(StockMovement::where('type', MovementType::Stocktake)->count())->toBe(2)
        ->and(Batch::firstWhere('default_key', Batch::defaultKey($this->empty->id, null))->unit_cost)->toBe('100.0000');
});

test('counts cannot be changed after counting is finished; reopening allows it again', function () {
    $stocktake = startStocktake();
    $lineId = $stocktake->lines()->value('id');

    $this->actingAs($this->manager)->post(route('inventory.stocktakes.finish', $stocktake));

    $this->actingAs($this->manager)->putJson(route('inventory.stocktakes.counts', $stocktake), ['counts' => [$lineId => '1']])->assertForbidden();

    $this->actingAs($this->manager)->post(route('inventory.stocktakes.reopen', $stocktake));
    $this->actingAs($this->manager)->putJson(route('inventory.stocktakes.counts', $stocktake), ['counts' => [$lineId => '1']])->assertOk();
});

test('differences above the approval limit need the owner to post', function () {
    $stocktake = startStocktake();
    $ureaLine = $stocktake->lines()->where('product_id', $this->urea->id)->value('id');

    // 100 kg short × 170 = 17,000.
    $this->actingAs($this->manager)->putJson(route('inventory.stocktakes.counts', $stocktake), ['counts' => [$ureaLine => '400']]);
    $this->actingAs($this->manager)->post(route('inventory.stocktakes.finish', $stocktake));

    $this->actingAs($this->manager)->post(route('inventory.stocktakes.post', $stocktake))->assertSessionHasErrors('status');
    expect((string) $this->stock->available($this->urea))->toBe('500.000');

    $this->actingAs($this->owner)->post(route('inventory.stocktakes.post', $stocktake))->assertSessionHasNoErrors();
    expect((string) $this->stock->available($this->urea))->toBe('400.000');
});

test('a cancelled stocktake changes nothing', function () {
    $stocktake = startStocktake();
    $this->actingAs($this->manager)->putJson(route('inventory.stocktakes.counts', $stocktake), ['counts' => [$stocktake->lines()->value('id') => '0']]);

    $this->actingAs($this->manager)->post(route('inventory.stocktakes.cancel', $stocktake))->assertRedirect();

    expect($stocktake->refresh()->status)->toBe(StocktakeStatus::Cancelled)
        ->and(StockMovement::where('type', MovementType::Stocktake)->count())->toBe(0);
});

test('stocktake pages render and sales staff cannot use them', function () {
    $stocktake = startStocktake();

    $this->actingAs($this->manager)->get(route('inventory.stocktakes.index'))->assertOk()->assertSee($stocktake->number);
    $this->actingAs($this->manager)->get(route('inventory.stocktakes.create'))->assertOk();
    $this->actingAs($this->manager)->get(route('inventory.stocktakes.count', $stocktake))->assertOk()->assertSee('Chilli seeds');
    $this->actingAs($this->manager)->get(route('inventory.stocktakes.sheet', $stocktake))->assertOk()->assertSee('Chilli seeds')->assertDontSee('500.000');
    $this->actingAs($this->manager)->get(route('inventory.stocktakes.show', [$stocktake, 'show' => 'all']))->assertOk();

    $staff = userWithRole(Role::SalesStaff);
    $this->actingAs($staff)->get(route('inventory.stocktakes.index'))->assertForbidden();
});
