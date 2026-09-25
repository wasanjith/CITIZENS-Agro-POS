<?php

use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Enums\Role;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierReturn;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([CatalogSeeder::class, DocumentSequenceSeeder::class]);
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->manager = userWithRole(Role::Manager);
    $this->staff = userWithRole(Role::SalesStaff);
    $this->supplier = Supplier::factory()->create(['opening_balance' => '1000.00']);
    $this->urea = createUrea();
    $this->bag = Unit::firstWhere('name', 'bag');
    $this->stock = app(StockService::class);
});

afterEach(function () {
    expectStockMatchesLedger();
});

/**
 * Staff raises 5 bags of urea, the manager approves at Rs. 8,500 a bag.
 */
function approvedUreaOrder(): PurchaseOrder
{
    $test = test();

    $test->actingAs($test->staff)->post(route('purchasing.purchase-orders.store'), [
        'supplier_id' => $test->supplier->id,
        'order_date' => now()->toDateString(),
        'lines' => [['product_id' => $test->urea->id, 'unit_id' => $test->bag->id, 'qty' => '5']],
        'action' => 'submit',
    ])->assertSessionHasNoErrors();

    $order = PurchaseOrder::latest('id')->firstOrFail();

    $test->actingAs($test->manager)
        ->post(route('purchasing.purchase-orders.approve', $order), ['costs' => [$order->lines()->value('id') => '8500']])
        ->assertSessionHasNoErrors();

    return $order->refresh();
}

function grnPayload(PurchaseOrder $order, string $qty, array $lineOverrides = [], array $overrides = []): array
{
    $line = $order->lines()->firstOrFail();

    return [
        'supplier_id' => $order->supplier_id,
        'purchase_order_id' => $order->id,
        'supplier_invoice_no' => 'INV-'.fake()->numberBetween(100, 999),
        'received_at' => now()->subMinute()->format('Y-m-d H:i'),
        'lines' => [[
            'po_line_id' => $line->id,
            'product_id' => $line->product_id,
            'variant_id' => '',
            'unit_id' => $line->unit_id,
            'qty' => $qty,
            'free_qty' => '',
            'unit_cost' => $line->unit_cost,
            ...$lineOverrides,
        ]],
        'action' => 'post',
        ...$overrides,
    ];
}

test('acceptance: a staff PO is approved, received in two deliveries, and stock and supplier balance are right', function () {
    $order = approvedUreaOrder();

    // First delivery: 2 of 5 bags.
    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.store'), grnPayload($order, '2'))->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Partial)
        ->and((string) $this->stock->available($this->urea))->toBe('100.000')
        ->and((string) $this->supplier->balance())->toBe('18000.00');

    // Second delivery: the remaining 3 bags.
    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.store'), grnPayload($order, '3'))->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($order->lines()->value('received_base_qty'))->toBe('250.000')
        ->and((string) $this->stock->available($this->urea))->toBe('250.000')
        ->and((string) $this->supplier->balance())->toBe('43500.00')
        ->and(GoodsReceipt::where('status', GoodsReceiptStatus::Posted)->count())->toBe(2)
        ->and(StockMovement::where('type', MovementType::Grn)->count())->toBe(2);

    // Cost per kg = 8,500 / 50.
    expect(Batch::firstWhere('product_id', $this->urea->id)->unit_cost)->toBe('170.0000')
        ->and($this->urea->refresh()->reference_cost)->toBe('170.0000')
        ->and(DB::table('supplier_products')->where('product_id', $this->urea->id)->value('last_cost'))->toBe('170.0000');
});

test('more than is outstanding on the order cannot be received', function () {
    $order = approvedUreaOrder();

    $this->actingAs($this->manager)
        ->post(route('purchasing.goods-receipts.store'), grnPayload($order, '6'))
        ->assertSessionHasErrors('lines.0.qty');

    expect(GoodsReceipt::count())->toBe(0)
        ->and((string) $this->stock->available($this->urea))->toBe('0.000');
});

test('a draft GRN does not change stock until it is posted, and a draft can be cancelled', function () {
    $order = approvedUreaOrder();

    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.store'), grnPayload($order, '2', overrides: ['action' => 'save']));
    $receipt = GoodsReceipt::firstOrFail();

    expect($receipt->status)->toBe(GoodsReceiptStatus::Draft)
        ->and((string) $this->stock->available($this->urea))->toBe('0.000')
        ->and($order->refresh()->status)->toBe(PurchaseOrderStatus::Approved);

    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.post', $receipt))->assertSessionHasNoErrors();

    expect($receipt->refresh()->status)->toBe(GoodsReceiptStatus::Posted)
        ->and((string) $this->stock->available($this->urea))->toBe('100.000');

    // Posted GRNs cannot be edited or cancelled.
    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.cancel', $receipt))->assertForbidden();

    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.store'), grnPayload($order, '1', overrides: ['action' => 'save']));
    $draft = GoodsReceipt::latest('id')->firstOrFail();
    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.cancel', $draft))->assertSessionHasNoErrors();

    expect($draft->refresh()->status)->toBe(GoodsReceiptStatus::Cancelled);
});

test('free goods and the GRN discount lower the batch cost', function () {
    $order = approvedUreaOrder();

    // 4 bags paid at 8,500 + 1 bag free, Rs. 3,400 discount: (34,000 − 3,400) / 250 kg = 122.40 per kg.
    $this->actingAs($this->manager)
        ->post(route('purchasing.goods-receipts.store'), grnPayload($order, '4', ['free_qty' => '1'], ['discount' => '3400']))
        ->assertSessionHasNoErrors();

    expect((string) $this->stock->available($this->urea))->toBe('250.000')
        ->and(Batch::firstWhere('product_id', $this->urea->id)->unit_cost)->toBe('122.4000')
        ->and(GoodsReceipt::firstOrFail()->total)->toBe('30600.00')
        ->and($order->refresh()->status)->toBe(PurchaseOrderStatus::Partial);
});

test('batch-tracked goods need an expiry date and get their own batch', function () {
    $seeds = createProduct(['name' => 'Chilli seeds', 'track_batches' => true, 'track_expiry' => true, 'base_unit' => 'packet']);
    $packet = Unit::firstWhere('name', 'packet');

    $payload = [
        'supplier_id' => $this->supplier->id,
        'received_at' => now()->subMinute()->format('Y-m-d H:i'),
        'lines' => [['product_id' => $seeds->id, 'unit_id' => $packet->id, 'qty' => '20', 'unit_cost' => '260', 'lot_no' => 'CS-01']],
        'action' => 'post',
    ];

    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.store'), $payload)->assertSessionHasErrors('lines.0.expiry_date');

    $payload['lines'][0]['expiry_date'] = now()->addYear()->toDateString();
    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.store'), $payload)->assertSessionHasNoErrors();

    $batch = Batch::firstWhere('product_id', $seeds->id);

    expect($batch->lot_no)->toBe('CS-01')
        ->and($batch->expiry_date->toDateString())->toBe(now()->addYear()->toDateString())
        ->and($batch->isDefault())->toBeFalse()
        ->and(GoodsReceipt::firstOrFail()->purchase_order_id)->toBeNull();
});

test('goods go back to the supplier from a chosen batch and the supplier is debited', function () {
    $order = approvedUreaOrder();
    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.store'), grnPayload($order, '2'));
    $receipt = GoodsReceipt::firstOrFail();
    $batch = Batch::firstWhere('product_id', $this->urea->id);

    $this->actingAs($this->manager)->post(route('purchasing.supplier-returns.store'), [
        'supplier_id' => $this->supplier->id,
        'goods_receipt_id' => $receipt->id,
        'return_date' => today()->toDateString(),
        'reason' => 'Torn bag',
        'lines' => [['product_id' => $this->urea->id, 'variant_id' => '', 'batch_id' => $batch->id, 'qty' => '50']],
    ])->assertSessionHasNoErrors();

    $return = SupplierReturn::firstOrFail();

    expect($return->number)->toStartWith('SRN-')
        ->and($return->total)->toBe('8500.00')
        ->and((string) $this->stock->available($this->urea))->toBe('50.000')
        ->and((string) $this->supplier->balance())->toBe('9500.00')
        ->and(StockMovement::where('type', MovementType::SupplierReturn)->value('qty'))->toBe('-50.000');
});

test('a supplier return cannot take more than the batch holds', function () {
    $batch = $this->stock->receive($this->urea, null, '10', '170');

    $this->actingAs($this->manager)->post(route('purchasing.supplier-returns.store'), [
        'supplier_id' => $this->supplier->id,
        'return_date' => today()->toDateString(),
        'reason' => 'Wrong item',
        'lines' => [['product_id' => $this->urea->id, 'batch_id' => $batch->id, 'qty' => '11']],
    ])->assertSessionHasErrors('stock');

    expect(SupplierReturn::count())->toBe(0)
        ->and((string) $this->stock->available($this->urea))->toBe('10.000');
});

test('sales staff cannot receive goods or return them', function () {
    $order = approvedUreaOrder();

    $this->actingAs($this->staff)->get(route('purchasing.goods-receipts.create', ['purchase_order' => $order->id]))->assertForbidden();
    $this->actingAs($this->staff)->post(route('purchasing.goods-receipts.store'), grnPayload($order, '1'))->assertForbidden();
    $this->actingAs($this->staff)->get(route('purchasing.supplier-returns.create'))->assertForbidden();
});

test('goods receipt, supplier and return pages render', function () {
    $order = approvedUreaOrder();
    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.store'), grnPayload($order, '2', overrides: ['action' => 'save']));
    $receipt = GoodsReceipt::firstOrFail();

    $this->actingAs($this->owner);

    $this->get(route('purchasing.goods-receipts.create', ['purchase_order' => $order->id]))->assertOk()->assertSee('Urea 50kg');
    $this->get(route('purchasing.goods-receipts.create'))->assertOk();
    $this->get(route('purchasing.goods-receipts.index'))->assertOk()->assertSee($receipt->number);
    $this->get(route('purchasing.goods-receipts.edit', $receipt))->assertOk();
    $this->get(route('purchasing.goods-receipts.show', $receipt))->assertOk();

    $this->post(route('purchasing.goods-receipts.post', $receipt));

    $this->get(route('purchasing.supplier-returns.create', ['goods_receipt' => $receipt->id]))->assertOk()->assertSee('Urea 50kg');
    $this->get(route('purchasing.supplier-returns.index'))->assertOk();
    $this->get(route('purchasing.suppliers.index'))->assertOk()->assertSee($this->supplier->name);
    $this->get(route('purchasing.suppliers.show', $this->supplier))->assertOk()->assertSee($receipt->number);
    $this->get(route('purchasing.suppliers.create'))->assertOk();
    $this->get(route('purchasing.suppliers.edit', $this->supplier))->assertOk();
    $this->getJson(route('api.purchasing.suppliers', ['q' => substr($this->supplier->name, 0, 4)]))->assertOk()->assertJsonPath('0.id', $this->supplier->id);
});

test('suppliers can be created and are deleted only without documents', function () {
    $this->actingAs($this->manager)->post(route('purchasing.suppliers.store'), [
        'name' => 'CIC Seeds', 'phone' => '0112345678', 'payment_terms_days' => '30', 'opening_balance' => '2500', 'is_active' => '1',
    ])->assertSessionHasNoErrors();

    $cic = Supplier::firstWhere('name', 'CIC Seeds');
    expect((string) $cic->balance())->toBe('2500.00')
        ->and($cic->whatsappNumber())->toBe('94112345678');

    $this->actingAs($this->manager)->delete(route('purchasing.suppliers.destroy', $cic))->assertRedirect();
    expect(Supplier::find($cic->id))->toBeNull();

    approvedUreaOrder();
    $this->actingAs($this->manager)->delete(route('purchasing.suppliers.destroy', $this->supplier));
    expect(Supplier::find($this->supplier->id))->not->toBeNull();

    $this->actingAs($this->staff)->get(route('purchasing.suppliers.index'))->assertForbidden();
});
