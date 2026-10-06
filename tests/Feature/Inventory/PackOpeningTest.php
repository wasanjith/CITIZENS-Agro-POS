<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Inventory\Actions\OpenPacksAction;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\PackOpening;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DocumentSequenceSeeder;

/*
 * Sealed bags opened into loose stock: on arrival (GRN "Open now") or later from
 * Inventory → Open packs when the loose stock runs out first.
 */

beforeEach(function () {
    $this->seed([CatalogSeeder::class, DocumentSequenceSeeder::class]);
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->manager = userWithRole(Role::Manager);
    $this->staff = userWithRole(Role::SalesStaff);
    $this->loose = createProduct(
        ['short_code' => '1100', 'name' => 'Urea (loose)', 'category' => 'Fertilizers', 'base_unit' => 'kg', 'sold_loose' => true],
        prices: ['Retail' => ['kg' => '300.00', 'kg@1' => '250.00']],
    );
    $this->bags = createProduct(
        ['short_code' => '1101', 'name' => 'Urea 50kg bag', 'category' => 'Fertilizers', 'base_unit' => 'bag', 'opens_into_product_id' => $this->loose->id, 'opens_into_qty' => '50'],
        prices: ['Retail' => ['bag' => '12000.00']],
    );
    $this->stock = app(StockService::class);
});

afterEach(function () {
    expectStockMatchesLedger();
});

test('opening bags moves the stock and its cost to the loose product', function () {
    $this->stock->receive($this->bags, null, '10', '10000');

    $opening = app(OpenPacksAction::class)->handle($this->bags, '3', null, $this->manager);

    expect((string) $this->stock->available($this->bags))->toBe('7.000')
        ->and((string) $this->stock->available($this->loose))->toBe('150.000')
        ->and($opening->number)->toStartWith('OPN-')
        ->and($opening->expected_qty)->toBe('150.000')
        ->and($opening->weighed_qty)->toBe('150.000')
        ->and($opening->cost_total)->toBe('30000.00')
        ->and($opening->goods_receipt_id)->toBeNull()
        ->and($this->loose->refresh()->reference_cost)->toBe('200.0000');

    $out = StockMovement::where('product_id', $this->bags->id)->where('type', MovementType::RepackOut)->sole();
    $in = StockMovement::where('product_id', $this->loose->id)->where('type', MovementType::RepackIn)->sole();

    expect($out->qty)->toBe('-3.000')
        ->and($out->reference->is($opening))->toBeTrue()
        ->and($in->qty)->toBe('150.000')
        ->and($in->unit_cost)->toBe('200.0000')
        ->and($in->reference->is($opening))->toBeTrue();
});

test('a short-weight opening keeps the weighed amount, so the cost per kg goes up', function () {
    $this->stock->receive($this->bags, null, '4', '10000');

    $opening = app(OpenPacksAction::class)->handle($this->bags, '2', '99', $this->manager);

    expect((string) $this->stock->available($this->loose))->toBe('99.000')
        ->and((string) $opening->difference())->toBe('-1.000')
        ->and($opening->cost_total)->toBe('20000.00')
        ->and($this->loose->refresh()->reference_cost)->toBe('202.0202');
});

test('bags cannot be opened without enough stock', function () {
    $this->stock->receive($this->bags, null, '1', '10000');

    expect(fn () => app(OpenPacksAction::class)->handle($this->bags, '2', null, $this->manager))
        ->toThrow(InsufficientStockException::class);

    expect(PackOpening::count())->toBe(0)
        ->and((string) $this->stock->available($this->loose))->toBe('0.000');
});

test('lot and expiry carry over to a loose product that tracks expiry', function () {
    $this->loose->update(['track_expiry' => true]);
    $this->stock->receive($this->bags, null, '2', '10000', ['lot_no' => 'L-77', 'expiry_date' => '2027-03-31']);

    app(OpenPacksAction::class)->handle($this->bags, '1', null, $this->manager);

    $batch = Batch::where('product_id', $this->loose->id)->sole();

    expect($batch->lot_no)->toBe('L-77')
        ->and($batch->expiry_date->toDateString())->toBe('2027-03-31');
});

test('the manager opens bags from the Open packs screen; sales staff cannot', function () {
    $this->stock->receive($this->bags, null, '5', '10000');

    $this->actingAs($this->manager)->get(route('inventory.pack-openings.create'))
        ->assertOk()
        ->assertSee('Urea 50kg bag');

    $response = $this->actingAs($this->manager)->post(route('inventory.pack-openings.store'), [
        'sealed_product_id' => $this->bags->id,
        'packs' => '1',
        'weighed_qty' => '',
        'note' => 'Loose urea ran out',
    ])->assertSessionHasNoErrors();

    $opening = PackOpening::sole();
    $response->assertRedirect(route('inventory.pack-openings.show', $opening));

    $this->actingAs($this->manager)->get(route('inventory.pack-openings.show', $opening))
        ->assertOk()
        ->assertSee($opening->number)
        ->assertSee('Loose urea ran out');

    $this->actingAs($this->manager)->get(route('inventory.pack-openings.index'))
        ->assertOk()
        ->assertSee($opening->number)
        ->assertSee('Shop stock');

    $this->actingAs($this->staff)->get(route('inventory.pack-openings.create'))->assertForbidden();
    $this->actingAs($this->staff)->post(route('inventory.pack-openings.store'), ['sealed_product_id' => $this->bags->id, 'packs' => '1'])->assertForbidden();
});

test('only products set up to be opened can be opened', function () {
    $this->actingAs($this->manager)->post(route('inventory.pack-openings.store'), [
        'sealed_product_id' => $this->loose->id,
        'packs' => '1',
    ])->assertSessionHasErrors('sealed_product_id');
});

test('not enough bags is shown as a stock error on the screen', function () {
    $this->actingAs($this->manager)
        ->from(route('inventory.pack-openings.create'))
        ->post(route('inventory.pack-openings.store'), ['sealed_product_id' => $this->bags->id, 'packs' => '1'])
        ->assertSessionHasErrors('stock');
});

/**
 * A GRN payload for bags of urea delivered without a purchase order.
 *
 * @param  array<string, mixed>  $line
 * @return array<string, mixed>
 */
function bagDeliveryPayload(Supplier $supplier, array $line): array
{
    return [
        'supplier_id' => $supplier->id,
        'supplier_invoice_no' => 'SUP-'.fake()->numberBetween(100, 999),
        'received_at' => now()->subMinute()->format('Y-m-d H:i'),
        'lines' => [[
            'product_id' => test()->bags->id,
            'variant_id' => '',
            'unit_id' => unitId('bag'),
            'qty' => '10',
            'free_qty' => '',
            'unit_cost' => '10000',
            ...$line,
        ]],
        'action' => 'post',
    ];
}

test('a GRN opens part of the delivery into loose stock', function () {
    $supplier = Supplier::factory()->create();

    $this->actingAs($this->manager)
        ->post(route('purchasing.goods-receipts.store'), bagDeliveryPayload($supplier, ['open_packs' => '3', 'open_weighed_qty' => '149']))
        ->assertSessionHasNoErrors();

    $receipt = GoodsReceipt::sole();
    $opening = PackOpening::sole();

    expect($receipt->status)->toBe(GoodsReceiptStatus::Posted)
        ->and((string) $this->stock->available($this->bags))->toBe('7.000')
        ->and((string) $this->stock->available($this->loose))->toBe('149.000')
        ->and($opening->goods_receipt_id)->toBe($receipt->id)
        ->and($opening->packs)->toBe('3.000')
        ->and($opening->cost_total)->toBe('30000.00');

    $this->actingAs($this->manager)->get(route('purchasing.goods-receipts.show', $receipt))
        ->assertOk()
        ->assertSee('Opened into loose stock')
        ->assertSee($opening->number);
});

test('a purchase order is fully received when some bags are opened on arrival', function () {
    $supplier = Supplier::factory()->create();

    $this->actingAs($this->staff)->post(route('purchasing.purchase-orders.store'), [
        'supplier_id' => $supplier->id,
        'order_date' => now()->toDateString(),
        'lines' => [['product_id' => $this->bags->id, 'unit_id' => unitId('bag'), 'qty' => '5']],
        'action' => 'submit',
    ])->assertSessionHasNoErrors();

    $order = PurchaseOrder::sole();
    $poLine = $order->lines()->sole();

    $this->actingAs($this->manager)
        ->post(route('purchasing.purchase-orders.approve', $order), ['costs' => [$poLine->id => '10000']])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.store'), [
        ...bagDeliveryPayload($supplier, ['po_line_id' => $poLine->id, 'qty' => '5', 'open_packs' => '2']),
        'purchase_order_id' => $order->id,
    ])->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and((string) $this->stock->available($this->bags))->toBe('3.000')
        ->and((string) $this->stock->available($this->loose))->toBe('100.000');
});

test('a GRN cannot open more than the line brings in, or a product that cannot be opened', function () {
    $supplier = Supplier::factory()->create();

    $this->actingAs($this->manager)
        ->post(route('purchasing.goods-receipts.store'), bagDeliveryPayload($supplier, ['open_packs' => '11']))
        ->assertSessionHasErrors('lines.0.open_packs');

    $this->actingAs($this->manager)
        ->post(route('purchasing.goods-receipts.store'), bagDeliveryPayload($supplier, ['product_id' => $this->loose->id, 'unit_id' => unitId('kg'), 'unit_cost' => '200', 'open_packs' => '1']))
        ->assertSessionHasErrors('lines.0.open_packs');

    expect(GoodsReceipt::count())->toBe(0)
        ->and(PackOpening::count())->toBe(0);
});
