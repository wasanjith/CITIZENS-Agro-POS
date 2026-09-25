<?php

use App\Domain\Catalog\Models\OpeningStockEntry;
use App\Domain\Identity\Enums\Role;
use App\Domain\Inventory\Actions\PostOpeningStockAction;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Jobs\LowStockAndExpiryAlertJob;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Notifications\LowStockAndExpiryAlert;
use App\Domain\Inventory\Services\StockAlerts;
use App\Domain\Inventory\Services\StockService;
use App\Domain\System\Services\Settings;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed([CatalogSeeder::class, DocumentSequenceSeeder::class]);
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->manager = userWithRole(Role::Manager);
    $this->staff = userWithRole(Role::SalesStaff);
    $this->urea = createUrea(['reorder_level' => 100]);
    $this->stock = app(StockService::class);
});

afterEach(function () {
    expectStockMatchesLedger();
});

test('opening stock from the import is posted once as OPENING movements', function () {
    OpeningStockEntry::create(['product_id' => $this->urea->id, 'qty' => '250', 'unit_cost' => '165']);
    OpeningStockEntry::create(['product_id' => $this->urea->id, 'qty' => '10', 'lot_no' => 'L1', 'expiry_date' => '2027-01-01']);

    $this->actingAs($this->manager)->post(route('inventory.stock.post-opening'))->assertSessionHasNoErrors();

    expect((string) $this->stock->available($this->urea))->toBe('260.000')
        ->and(StockMovement::where('type', MovementType::Opening)->count())->toBe(2)
        ->and(OpeningStockEntry::whereNull('posted_at')->count())->toBe(0)
        // The second entry has no cost of its own: the product's reference cost is used.
        ->and(StockMovement::where('type', MovementType::Opening)->orderBy('id')->pluck('unit_cost')->all())->toBe(['165.0000', '160.0000']);

    expect(app(PostOpeningStockAction::class)->handle())->toBe(0)
        ->and((string) $this->stock->available($this->urea))->toBe('260.000');
});

test('stock pages render for the owner', function () {
    $this->stock->receive($this->urea, null, '50', '170');
    $this->stock->receive(createProduct(['name' => 'Seeds', 'track_expiry' => true]), null, '5', '10', ['expiry_date' => now()->addDays(10)->toDateString()]);

    $this->actingAs($this->owner);

    $this->get(route('inventory.stock.index'))->assertOk()->assertSee('Urea 50kg')->assertSee('Stock value at cost');
    $this->get(route('inventory.stock.index', ['view' => 'batches']))->assertOk()->assertSee('General stock');
    $this->get(route('inventory.stock.index', ['filter' => ['low' => '1']]))->assertOk()->assertSee('Urea 50kg');
    $this->get(route('inventory.batches.expiry', ['days' => 30]))->assertOk()->assertSee('Seeds');
    $this->get(route('inventory.movements.index', ['product' => $this->urea->id]))->assertOk()->assertSee('Goods received');
    $this->get(route('catalog.products.show', $this->urea))->assertOk()->assertSee('Stock by batch');
    $this->get(route('catalog.products.index', ['filter' => ['low' => '1']]))->assertOk()->assertSee('Urea 50kg');
});

test('sales staff see stock but never its value or cost', function () {
    $this->stock->receive($this->urea, null, '50', '170');

    $this->actingAs($this->staff)
        ->get(route('inventory.stock.index'))
        ->assertOk()
        ->assertSee('Urea 50kg')
        ->assertDontSee('Stock value')
        ->assertDontSee('8,500.00');

    $this->actingAs($this->staff)
        ->getJson(route('api.inventory.batches', ['product_id' => $this->urea->id]))
        ->assertOk()
        ->assertJsonMissingPath('batches.0.unit_cost');

    $this->actingAs($this->staff)->get(route('inventory.adjustments.index'))->assertForbidden();
});

test('product search returns live stock (on hand minus reserved)', function () {
    $this->stock->receive($this->urea, null, '100', '170');
    $this->stock->reserve($this->urea, null, '30');

    $item = $this->actingAs($this->staff)
        ->getJson(route('api.pos.search', ['q' => '1001']))
        ->assertOk()
        ->json('items.0');

    expect($item['stock'])->toBe('70.000')
        ->and($item['base_unit'])->toBe('kg')
        ->and($item)->not->toHaveKey('reference_cost');
});

test('the daily alert tells the owner and manager about low stock and expiring batches', function () {
    Notification::fake();

    $this->stock->receive(createProduct(['track_expiry' => true]), null, '5', '10', ['expiry_date' => now()->addDays(5)->toDateString()]);

    (new LowStockAndExpiryAlertJob)->handle(app(StockAlerts::class), app(Settings::class));

    Notification::assertSentTo([$this->owner, $this->manager], LowStockAndExpiryAlert::class, fn ($alert) => $alert->lowStockCount === 1 && $alert->expiringCount === 1);
    Notification::assertNotSentTo($this->staff, LowStockAndExpiryAlert::class);
});

test('no alert is sent when stock is fine', function () {
    Notification::fake();
    $this->stock->receive($this->urea, null, '500', '170');

    app()->call([new LowStockAndExpiryAlertJob, 'handle']);

    Notification::assertNothingSent();
});

test('notifications open their page and are marked read', function () {
    $this->owner->notify(new LowStockAndExpiryAlert(1, 0, 30));
    $notification = $this->owner->notifications()->firstOrFail();

    $this->actingAs($this->owner)->get(route('notifications.index'))->assertOk()->assertSee('Stock alert');
    $this->actingAs($this->owner)->get(route('notifications.open', $notification->id))->assertRedirect(route('inventory.stock.index', ['filter' => ['low' => 1]]));

    expect($notification->refresh()->read_at)->not->toBeNull();

    $this->actingAs($this->manager)->get(route('notifications.open', $notification->id))->assertNotFound();
});
