<?php

use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Models\CustomerLedgerEntry;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Enums\RefundMethod;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Sales\Support\Money;
use Illuminate\Support\Str;
use Tests\TestCase;

beforeEach(function () {
    $this->pos = posSetup();
    $this->seedProduct = createProduct(['name' => 'Paddy seed BG 352', 'name_si' => 'වී බීජ', 'base_unit' => 'packet', 'track_expiry' => true], prices: ['Retail' => ['packet' => '450.00']]);
    $stock = app(StockService::class);
    $this->late = $stock->receive($this->seedProduct, null, '10', '300', ['lot_no' => 'LATE', 'expiry_date' => now()->addMonths(6)->toDateString()]);
    $this->early = $stock->receive($this->seedProduct, null, '4', '280', ['lot_no' => 'EARLY', 'expiry_date' => now()->addMonths(2)->toDateString()]);
    $this->session = openDrawer($this->pos['main'], $this->pos['owner']);
});

/**
 * A settled sale of seed packets (4 come from the EARLY batch, then the LATE one).
 */
function settledSeedSale(TestCase $test, string $packets, array $extra = []): Sale
{
    $sale = counterInvoice($test->pos, [['product_id' => $test->seedProduct->id, 'unit_id' => unitId('packet'), 'qty' => $packets]], ['tendered' => '100000', ...$extra]);
    cashierSettle($test->pos, $test->pos['owner'], $sale)->assertOk();

    return $sale->refresh()->load('items');
}

function takeReturn(array $pos, Sale $sale, array $lines, string $method = 'cash', ?string $key = null)
{
    return atTerminal($pos['mainToken'], $pos['owner'])->post(route('pos.returns.store', $sale), [
        'reason' => 'Customer changed mind',
        'refund_method' => $method,
        'lines' => $lines,
        'idempotency_key' => $key ?? Str::random(32),
    ]);
}

test('a partial cash return puts stock back into the batch it was sold from and refunds from the drawer', function () {
    $sale = settledSeedSale($this, '6');
    $item = $sale->items->sole();

    takeReturn($this->pos, $sale, [$item->id => ['qty' => '2', 'restock' => '1']])->assertRedirect()->assertSessionHas('print_url');

    $return = SaleReturn::sole();
    expect($return->number)->toStartWith('RET-')
        ->and($return->total)->toBe('900.00')
        ->and($return->refund_method)->toBe(RefundMethod::Cash)
        // 2 packets back into the EARLY batch (issued first) at its cost.
        ->and($return->cost_total)->toBe('560.00')
        ->and($return->lines->sole()->batches->sole()->batch_id)->toBe($this->early->id)
        ->and(StockLevel::where('batch_id', $this->early->id)->value('qty_on_hand'))->toBe('2.000')
        ->and($sale->refresh()->status)->toBe(SaleStatus::PartiallyReturned);

    expect(Payment::where('amount', '<', 0)->sole()->amount)->toBe('-900.00')
        // 5000 float + 2700 sale − 900 refund.
        ->and((string) app(DrawerCalculator::class)->expectedCash($this->session))->toBe('6800.00')
        ->and(app(DrawerCalculator::class)->summary($this->session)['cash_refunds'])->toBe('900.00');

    expect(PrintJob::where('document_type', PrintDocumentType::SaleReturn)->where('document_id', $return->id)->exists())->toBeTrue();
    expectStockMatchesLedger();
});

test('a return cannot exceed what was sold minus what already came back', function () {
    $sale = settledSeedSale($this, '6');
    $item = $sale->items->sole();

    takeReturn($this->pos, $sale, [$item->id => ['qty' => '7', 'restock' => '1']])->assertSessionHasErrors("lines.{$item->id}.qty");

    takeReturn($this->pos, $sale, [$item->id => ['qty' => '4', 'restock' => '1']])->assertRedirect();
    takeReturn($this->pos, $sale, [$item->id => ['qty' => '3', 'restock' => '1']])->assertSessionHasErrors("lines.{$item->id}.qty");
    takeReturn($this->pos, $sale, [$item->id => ['qty' => '2', 'restock' => '1']])->assertRedirect();

    expect($sale->refresh()->status)->toBe(SaleStatus::Returned)
        ->and(SaleReturn::sum('total'))->toEqual(2700);

    // The second return went back into what was left: EARLY 4 − 4, then LATE 2.
    expect(StockLevel::where('batch_id', $this->early->id)->value('qty_on_hand'))->toBe('4.000')
        ->and(StockLevel::where('batch_id', $this->late->id)->value('qty_on_hand'))->toBe('10.000');

    takeReturn($this->pos, $sale, [$item->id => ['qty' => '1', 'restock' => '1']])->assertSessionHasErrors('sale');
    expectStockMatchesLedger();
});

test('damaged goods come back and are written off as damage', function () {
    $sale = settledSeedSale($this, '2');
    $item = $sale->items->sole();

    takeReturn($this->pos, $sale, [$item->id => ['qty' => '1', 'restock' => '0']])->assertRedirect();

    $return = SaleReturn::sole();
    expect(StockMovement::where('reference_type', 'sale_return')->where('reference_id', $return->id)->where('type', MovementType::SaleReturn)->sum('qty'))->toEqual(1)
        ->and(StockMovement::where('reference_type', 'sale_return')->where('reference_id', $return->id)->where('type', MovementType::Damage)->sum('qty'))->toEqual(-1)
        // Still 2 of 4 left in EARLY: nothing went back on the shelf.
        ->and(StockLevel::where('batch_id', $this->early->id)->value('qty_on_hand'))->toBe('2.000')
        ->and($return->lines->sole()->restock)->toBeFalse();
    expectStockMatchesLedger();
});

test('refunds share the bill discount, and a full return adds up to the invoice total to the cent', function () {
    $urea = ureaInStock();
    $lines = [
        ['product_id' => $this->seedProduct->id, 'unit_id' => unitId('packet'), 'qty' => '3'],
        ['product_id' => $urea->id, 'unit_id' => unitId('kg'), 'qty' => '7'],
    ];
    // 1350 + 1330 = 2680, bill discount 100.
    $sale = counterInvoice($this->pos, $lines, ['tendered' => '5000', 'bill_discount' => '100']);
    cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk();
    $sale->refresh()->load('items');
    [$seeds, $kg] = $sale->items->all();

    takeReturn($this->pos, $sale, [$seeds->id => ['qty' => '1', 'restock' => '1']])->assertRedirect();
    // 1350 − 100 × 1350 / 2680 = 1299.63; one of three packets = 433.21.
    expect(SaleReturn::latest('id')->first()->total)->toBe('433.21');

    takeReturn($this->pos, $sale, [$seeds->id => ['qty' => '2', 'restock' => '1'], $kg->id => ['qty' => '7', 'restock' => '1']])->assertRedirect();

    expect((string) Money::of((string) SaleReturn::sum('total')))->toBe($sale->total)
        ->and($sale->refresh()->status)->toBe(SaleStatus::Returned);
    expectStockMatchesLedger();
});

test('returning to the account lowers what a credit invoice still owes; cash is refused while it is unpaid', function () {
    $customer = creditCustomer();
    $sale = settledSeedSale($this, '4', ['customer_id' => $customer->id, 'payment_method' => 'credit']);
    $item = $sale->items->sole();
    expect($sale->balance_due)->toBe('1800.00');

    takeReturn($this->pos, $sale, [$item->id => ['qty' => '1', 'restock' => '1']], 'cash')->assertSessionHasErrors('refund_method');
    takeReturn($this->pos, $sale, [$item->id => ['qty' => '1', 'restock' => '1']], 'account')->assertRedirect();

    expect($sale->refresh()->balance_due)->toBe('1350.00')
        ->and(CustomerLedgerEntry::where('type', CustomerLedgerType::Return)->value('credit'))->toBe('450.00')
        ->and((string) $customer->balance())->toBe('1350.00')
        // No money left the drawer.
        ->and(Payment::where('amount', '<', 0)->count())->toBe(0);
});

test('a walk-in sale can only be refunded in cash', function () {
    $sale = settledSeedSale($this, '1');

    takeReturn($this->pos, $sale, [$sale->items->sole()->id => ['qty' => '1', 'restock' => '1']], 'account')->assertSessionHasErrors('refund_method');
    expect(SaleReturn::count())->toBe(0);
});

test('only settled invoices take returns; a returned invoice can no longer be voided', function () {
    $waiting = counterInvoice($this->pos, [['product_id' => $this->seedProduct->id, 'unit_id' => unitId('packet'), 'qty' => '1']], ['tendered' => '450']);
    takeReturn($this->pos, $waiting, [$waiting->items()->sole()->id => ['qty' => '1', 'restock' => '1']])->assertSessionHasErrors('sale');

    $sale = settledSeedSale($this, '2');
    takeReturn($this->pos, $sale, [$sale->items->sole()->id => ['qty' => '1', 'restock' => '1']])->assertRedirect();

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->postJson(route('pos.sales.void', $sale), ['reason' => 'test'])
        ->assertUnprocessable();
});

test('the same return sent twice is taken once', function () {
    $sale = settledSeedSale($this, '3');
    $key = Str::random(32);
    $lines = [$sale->items->sole()->id => ['qty' => '1', 'restock' => '1']];

    takeReturn($this->pos, $sale, $lines, 'cash', $key)->assertRedirect();
    takeReturn($this->pos, $sale, $lines, 'cash', $key)->assertRedirect();

    expect(SaleReturn::count())->toBe(1);
    expectStockMatchesLedger();
});

test('returns need pos.refund at the main cashier', function () {
    $sale = settledSeedSale($this, '1');
    $lines = [$sale->items->sole()->id => ['qty' => '1', 'restock' => '1']];

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->post(route('pos.returns.store', $sale), ['reason' => 'x', 'refund_method' => 'cash', 'lines' => $lines, 'idempotency_key' => Str::random(32)])
        ->assertForbidden();
    atTerminal($this->pos['mainToken'], $this->pos['manager'])
        ->post(route('pos.returns.store', $sale), ['reason' => 'x', 'refund_method' => 'cash', 'lines' => $lines, 'idempotency_key' => Str::random(32)])
        ->assertForbidden();

    expect(SaleReturn::count())->toBe(0);
});

test('return pages and the Sinhala return receipt render', function () {
    $sale = settledSeedSale($this, '2');

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->get(route('pos.returns.create', ['invoice' => $sale->shortNumber()]))
        ->assertOk()
        ->assertSee($sale->invoice_no)
        ->assertSee('Paddy seed BG 352');

    takeReturn($this->pos, $sale, [$sale->items->sole()->id => ['qty' => '1', 'restock' => '1']])->assertRedirect();
    $return = SaleReturn::sole();

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->get(route('pos.returns.receipt', $return))
        ->assertOk()
        ->assertSee('ආපසු භාරදීමේ රිසිට්පත')
        ->assertSee('වී බීජ')
        ->assertSee($sale->invoice_no);

    $this->actingAs($this->pos['owner'])->get(route('sales.returns.index'))->assertOk()->assertSee($return->number);
    $this->actingAs($this->pos['owner'])->get(route('sales.returns.show', $return))->assertOk()->assertSee('Back on the shelf');
    $this->actingAs($this->pos['owner'])->get(route('sales.show', $sale))->assertOk()->assertSee($return->number);

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->get(route('pos.drawer.report', $this->session))
        ->assertOk()
        ->assertSee('ආපසු ගෙවූ මුදල්');
});
