<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Enums\Role;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Purchasing\Actions\SavePurchaseOrderAction;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Notifications\PurchaseOrderReviewed;
use App\Domain\Purchasing\Notifications\PurchaseOrderSubmitted;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed([CatalogSeeder::class, DocumentSequenceSeeder::class]);
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->manager = userWithRole(Role::Manager);
    $this->staff = userWithRole(Role::SalesStaff);
    $this->supplier = Supplier::factory()->create(['name' => 'Lanka Fertilizer Co', 'phone' => '0771234567']);
    $this->urea = createUrea();
    $this->bag = Unit::firstWhere('name', 'bag');
});

function makeBagThePurchaseUnit(Product $product): void
{
    $bagId = Unit::where('name', 'bag')->value('id');
    $product->units()->update(['is_default_purchase' => false]);
    $product->units()->where('unit_id', $bagId)->update(['is_default_purchase' => true]);
}

function poPayload(array $overrides = []): array
{
    $test = test();

    return [
        'supplier_id' => $test->supplier->id,
        'order_date' => now()->toDateString(),
        'expected_date' => now()->addWeek()->toDateString(),
        'note' => 'Deliver to the back store',
        'lines' => [
            ['product_id' => $test->urea->id, 'variant_id' => '', 'unit_id' => $test->bag->id, 'qty' => '5', 'unit_cost' => '8500'],
        ],
        ...$overrides,
    ];
}

test('sales staff raise and submit a purchase order with quantities only; approvers are told', function () {
    Notification::fake();

    $this->actingAs($this->staff)
        ->post(route('purchasing.purchase-orders.store'), poPayload(['action' => 'submit']))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $order = PurchaseOrder::with('lines')->firstOrFail();
    $line = $order->lines->first();

    expect($order->number)->toStartWith('PO-')
        ->and($order->status)->toBe(PurchaseOrderStatus::Submitted)
        ->and($order->created_by)->toBe($this->staff->id)
        ->and($line->base_qty)->toBe('250.000')
        ->and($line->unit_cost)->toBeNull()
        ->and($order->total)->toBe('0.00');

    Notification::assertSentTo([$this->owner, $this->manager], PurchaseOrderSubmitted::class);
    Notification::assertNotSentTo($this->staff, PurchaseOrderSubmitted::class);
});

test('sales staff cannot approve and never see cost prices', function () {
    $order = app(SavePurchaseOrderAction::class)->handle(poPayload(), $this->manager);

    $this->actingAs($this->staff)
        ->post(route('purchasing.purchase-orders.approve', $order), ['costs' => [$order->lines()->value('id') => '8500']])
        ->assertForbidden();

    $this->actingAs($this->staff)
        ->get(route('purchasing.purchase-orders.show', $order))
        ->assertOk()
        ->assertDontSee('8,500.00')
        ->assertDontSee('Unit cost');

    $this->actingAs($this->staff)
        ->get(route('purchasing.purchase-orders.edit', $order))
        ->assertForbidden();

    $this->actingAs($this->staff)
        ->get(route('purchasing.purchase-orders.pdf', $order))
        ->assertForbidden();
});

test('the reorder suggestions JSON has no costs for sales staff', function () {
    makeBagThePurchaseUnit($this->urea);
    $this->supplier->products()->attach($this->urea->id, ['last_cost' => '170']);
    $this->urea->update(['reorder_level' => 100, 'reorder_qty' => 500]);

    $staffLines = $this->actingAs($this->staff)
        ->getJson(route('purchasing.purchase-orders.suggestions', ['supplier_id' => $this->supplier->id]))
        ->assertOk()
        ->json('lines');

    expect($staffLines)->toHaveCount(1)
        ->and($staffLines[0]['qty'])->toBe('10')
        ->and($staffLines[0]['unit_cost'])->toBeNull()
        ->and($staffLines[0])->not->toHaveKey('reference_cost');

    $managerLines = $this->actingAs($this->manager)
        ->getJson(route('purchasing.purchase-orders.suggestions', ['supplier_id' => $this->supplier->id]))
        ->json('lines');

    expect($managerLines[0]['unit_cost'])->toBe('8500.00');
});

test('reorder suggestions skip products that have enough stock', function () {
    makeBagThePurchaseUnit($this->urea);
    $this->urea->update(['reorder_level' => 100, 'reorder_qty' => 0]);
    app(StockService::class)->receive($this->urea, null, '40', '160');

    $lines = $this->actingAs($this->manager)
        ->getJson(route('purchasing.purchase-orders.suggestions', ['supplier_id' => $this->supplier->id]))
        ->json('lines');

    // reorder_level × 2 − on hand = 160 kg = 3.2 bags → 4 whole bags.
    expect($lines[0]['qty'])->toBe('4');

    app(StockService::class)->receive($this->urea, null, '100', '160');

    expect($this->actingAs($this->manager)->getJson(route('purchasing.purchase-orders.suggestions', ['supplier_id' => $this->supplier->id]))->json('lines'))->toBe([]);
});

test('a manager approves with costs, totals are worked out and the creator is told', function () {
    Notification::fake();
    $this->actingAs($this->staff)->post(route('purchasing.purchase-orders.store'), poPayload(['action' => 'submit']));
    $order = PurchaseOrder::firstOrFail();
    $lineId = $order->lines()->value('id');

    $this->actingAs($this->manager)
        ->post(route('purchasing.purchase-orders.approve', $order), ['costs' => [$lineId => '8500'], 'discount' => '500', 'tax' => '0'])
        ->assertSessionHasNoErrors();

    $order->refresh();

    expect($order->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($order->approved_by)->toBe($this->manager->id)
        ->and($order->subtotal)->toBe('42500.00')
        ->and($order->total)->toBe('42000.00')
        ->and($this->supplier->products()->pluck('products.id')->all())->toBe([$this->urea->id]);

    Notification::assertSentTo($this->staff, PurchaseOrderReviewed::class, fn ($notification) => $notification->approved);
});

test('approval needs a cost on every line', function () {
    $this->actingAs($this->staff)->post(route('purchasing.purchase-orders.store'), poPayload(['action' => 'submit']));
    $order = PurchaseOrder::firstOrFail();

    $this->actingAs($this->manager)
        ->post(route('purchasing.purchase-orders.approve', $order), ['costs' => [$order->lines()->value('id') => '']])
        ->assertSessionHasErrors('costs.'.$order->lines()->value('id'));

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Submitted);
});

test('a rejected order goes back to its creator, who can change and resubmit it', function () {
    $this->actingAs($this->staff)->post(route('purchasing.purchase-orders.store'), poPayload(['action' => 'submit']));
    $order = PurchaseOrder::firstOrFail();

    $this->actingAs($this->manager)
        ->post(route('purchasing.purchase-orders.reject', $order), ['reason' => 'Order 3 bags only'])
        ->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Rejected)
        ->and($order->rejected_reason)->toBe('Order 3 bags only');

    $payload = poPayload(['action' => 'submit']);
    $payload['lines'][0]['qty'] = '3';

    $this->actingAs($this->staff)
        ->put(route('purchasing.purchase-orders.update', $order), $payload)
        ->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Submitted)
        ->and($order->rejected_reason)->toBeNull()
        ->and($order->lines()->value('qty'))->toBe('3.000');
});

test('sales staff cannot change someone else\'s draft', function () {
    $this->actingAs($this->manager)->post(route('purchasing.purchase-orders.store'), poPayload());
    $order = PurchaseOrder::firstOrFail();

    $this->actingAs($this->staff)
        ->put(route('purchasing.purchase-orders.update', $order), poPayload())
        ->assertForbidden();
});

test('a product with variants needs the variant chosen, and units must belong to the product', function () {
    $tyre = createProduct(['name' => 'Tyre'], variants: [['short_code' => 'T26', 'name' => '26"', 'is_active' => true]]);

    $this->actingAs($this->staff)
        ->post(route('purchasing.purchase-orders.store'), poPayload(['lines' => [
            ['product_id' => $tyre->id, 'unit_id' => $this->bag->id, 'qty' => '2'],
        ]]))
        ->assertSessionHasErrors(['lines.0.variant_id', 'lines.0.unit_id']);
});

test('whole-number units reject fractions', function () {
    $this->actingAs($this->staff)
        ->post(route('purchasing.purchase-orders.store'), poPayload(['lines' => [
            ['product_id' => $this->urea->id, 'unit_id' => $this->bag->id, 'qty' => '2.5'],
        ]]))
        ->assertSessionHasErrors('lines.0.qty');
});

test('an approved order is marked sent, and can be cancelled while nothing was received', function () {
    $this->actingAs($this->manager)->post(route('purchasing.purchase-orders.store'), poPayload());
    $order = PurchaseOrder::firstOrFail();
    $this->actingAs($this->manager)->post(route('purchasing.purchase-orders.approve', $order), ['costs' => [$order->lines()->value('id') => '8500']]);

    $this->actingAs($this->manager)->post(route('purchasing.purchase-orders.send', $order))->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and($order->sent_at)->not->toBeNull();

    $this->actingAs($this->manager)->post(route('purchasing.purchase-orders.cancel', $order))->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
});

test('the shared PDF link only works with a valid signature', function () {
    $this->actingAs($this->manager)->post(route('purchasing.purchase-orders.store'), poPayload());
    $order = PurchaseOrder::firstOrFail();

    auth()->logout();

    $this->get(route('purchasing.purchase-orders.shared-pdf', $order))->assertForbidden();
    $this->get(URL::temporarySignedRoute('purchasing.purchase-orders.shared-pdf', now()->subMinute(), ['purchase_order' => $order->id]))->assertForbidden();
});

test('purchase order pages render', function () {
    $this->actingAs($this->staff)->post(route('purchasing.purchase-orders.store'), poPayload());
    $order = PurchaseOrder::firstOrFail();

    foreach ([$this->owner, $this->staff] as $user) {
        $this->actingAs($user)->get(route('purchasing.purchase-orders.index'))->assertOk()->assertSee($order->number);
        $this->actingAs($user)->get(route('purchasing.purchase-orders.create', ['supplier_id' => $this->supplier->id]))->assertOk()->assertSee('Lanka Fertilizer Co');
        $this->actingAs($user)->get(route('purchasing.purchase-orders.show', $order))->assertOk()->assertSee('Urea 50kg');
        $this->actingAs($user)->get(route('purchasing.purchase-orders.edit', $order))->assertOk();
    }
});
