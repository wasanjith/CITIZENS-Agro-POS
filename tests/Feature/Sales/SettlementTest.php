<?php

use App\Domain\CashDrawer\Actions\CloseDrawerAction;
use App\Domain\CashDrawer\Enums\DrawerCloseReason;
use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Identity\Actions\CreateDelegationAction;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Sales\Actions\IssueCounterInvoiceAction;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Services\LiveCartStore;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->pos = posSetup();
    $this->bag = unitId('bag');
});

/**
 * Print an invoice at Counter 1 (through the action, as the counter would).
 */
function invoiceAtCounter(array $pos, array $lines, string $tendered): Sale
{
    return app(IssueCounterInvoiceAction::class)->handle(
        $pos['counter'],
        $pos['staff'],
        cartPayload($lines, ['tendered' => $tendered]),
        Str::random(32),
    )['sale'];
}

function settleAtMain(array $pos, $user, Sale $sale, ?string $key = null, array $extra = [])
{
    return atTerminal($pos['mainToken'], $user)
        ->postJson(route('api.pos.sales.settle', $sale), ['idempotency_key' => $key ?? Str::random(32), ...$extra]);
}

test('issue then settle: stock reserved, then issued FEFO from the right batches; payment in the open drawer', function () {
    $seed = createProduct(['name' => 'Paddy seed BG 352', 'base_unit' => 'packet', 'track_expiry' => true], prices: ['Retail' => ['packet' => '450.00']]);
    $stock = app(StockService::class);
    $late = $stock->receive($seed, null, '10', '300', ['lot_no' => 'LATE', 'expiry_date' => now()->addMonths(6)->toDateString()]);
    $early = $stock->receive($seed, null, '4', '280', ['lot_no' => 'EARLY', 'expiry_date' => now()->addMonths(2)->toDateString()]);

    $sale = invoiceAtCounter($this->pos, [['product_id' => $seed->id, 'unit_id' => unitId('packet'), 'qty' => '6']], '3000');
    expect(StockLevel::where('product_id', $seed->id)->sum('qty_reserved'))->toEqual(6);

    $session = openDrawer($this->pos['main'], $this->pos['owner']);

    settleAtMain($this->pos, $this->pos['owner'], $sale)->assertOk()->assertJsonPath('open_drawer', true);

    $sale->refresh();
    expect($sale->status)->toBe(SaleStatus::Settled)
        ->and($sale->drawer_session_id)->toBe($session->id)
        ->and($sale->settled_by)->toBe($this->pos['owner']->id)
        // 4 × 280 from the early batch + 2 × 300 from the late one.
        ->and($sale->cost_total)->toBe('1720.00');

    $batches = $sale->items->sole()->batches->pluck('base_qty', 'batch_id');
    expect($batches[$early->id])->toBe('4.000')->and($batches[$late->id])->toBe('2.000');

    expect(StockLevel::where('batch_id', $early->id)->value('qty_on_hand'))->toBe('0.000')
        ->and(StockLevel::where('batch_id', $late->id)->value('qty_on_hand'))->toBe('8.000')
        ->and(StockLevel::where('product_id', $seed->id)->sum('qty_reserved'))->toEqual(0)
        ->and(StockMovement::where('type', MovementType::Sale)->where('reference_id', $sale->id)->sum('qty'))->toEqual(-6);

    $payment = Payment::sole();
    expect($payment->drawer_session_id)->toBe($session->id)
        ->and($payment->amount)->toBe('2700.00')
        ->and($payment->recorded_by)->toBe($this->pos['staff']->id)
        ->and($payment->confirmed_by)->toBe($this->pos['owner']->id);

    expect((string) app(DrawerCalculator::class)->expectedCash($session))->toBe('7700.00');
    expectStockMatchesLedger();
});

test('settling twice with the same key settles once; another key is refused', function () {
    $urea = ureaInStock();
    $sale = invoiceAtCounter($this->pos, [['product_id' => $urea->id, 'unit_id' => $this->bag, 'qty' => '1']], '9000');
    openDrawer($this->pos['main'], $this->pos['owner']);
    $key = Str::random(32);

    settleAtMain($this->pos, $this->pos['owner'], $sale, $key)->assertOk()->assertJsonPath('created', true);
    settleAtMain($this->pos, $this->pos['owner'], $sale, $key)->assertOk()->assertJsonPath('created', false)->assertJsonPath('open_drawer', false);
    settleAtMain($this->pos, $this->pos['owner'], $sale)->assertUnprocessable();

    expect(Payment::count())->toBe(1)
        ->and(StockMovement::where('type', MovementType::Sale)->count())->toBe(1);
    expectStockMatchesLedger();
});

test('card payments need a reference at settlement', function () {
    $urea = ureaInStock();
    $sale = app(IssueCounterInvoiceAction::class)->handle($this->pos['counter'], $this->pos['staff'], cartPayload([['product_id' => $urea->id, 'unit_id' => $this->bag, 'qty' => '1']], ['payment_method' => 'card']), Str::random(32))['sale'];
    $session = openDrawer($this->pos['main'], $this->pos['owner']);

    settleAtMain($this->pos, $this->pos['owner'], $sale)->assertUnprocessable()->assertJsonValidationErrors('reference');
    settleAtMain($this->pos, $this->pos['owner'], $sale, extra: ['reference' => 'SLIP-4411'])->assertOk()->assertJsonPath('open_drawer', false);

    expect(Payment::sole()->reference)->toBe('SLIP-4411')
        // Card money is not in the drawer.
        ->and((string) app(DrawerCalculator::class)->expectedCash($session))->toBe('5000.00');
});

test('who may settle: staff and an undelegated manager no; a delegated manager on the main terminal yes, on a counter no', function () {
    $urea = ureaInStock();
    $sale = invoiceAtCounter($this->pos, [['product_id' => $urea->id, 'unit_id' => $this->bag, 'qty' => '1']], '9000');

    settleAtMain($this->pos, $this->pos['staff'], $sale)->assertForbidden();
    settleAtMain($this->pos, $this->pos['manager'], $sale)->assertForbidden();

    // With a delegation and their own drawer session on the main terminal.
    app(CreateDelegationAction::class)->handle($this->pos['owner'], $this->pos['manager'], now()->addHours(4));
    openDrawer($this->pos['main'], $this->pos['manager']);

    atTerminal($this->pos['counterToken'], $this->pos['manager'])
        ->postJson(route('api.pos.sales.settle', $sale), ['idempotency_key' => Str::random(32)])
        ->assertForbidden();

    settleAtMain($this->pos, $this->pos['manager'], $sale)->assertOk();
    expect($sale->refresh()->status)->toBe(SaleStatus::Settled);
});

test('the owner cannot settle while the Manager holds the drawer', function () {
    $urea = ureaInStock();
    $sale = invoiceAtCounter($this->pos, [['product_id' => $urea->id, 'unit_id' => $this->bag, 'qty' => '1']], '9000');
    app(CreateDelegationAction::class)->handle($this->pos['owner'], $this->pos['manager'], now()->addHours(4));
    openDrawer($this->pos['main'], $this->pos['manager']);

    settleAtMain($this->pos, $this->pos['owner'], $sale)->assertForbidden();
});

test('an expired delegation blocks settlement straight away', function () {
    $urea = ureaInStock();
    $sale = invoiceAtCounter($this->pos, [['product_id' => $urea->id, 'unit_id' => $this->bag, 'qty' => '1']], '9000');
    app(CreateDelegationAction::class)->handle($this->pos['owner'], $this->pos['manager'], now()->addMinutes(30));
    openDrawer($this->pos['main'], $this->pos['manager']);

    $this->travel(31)->minutes();

    settleAtMain($this->pos, $this->pos['manager'], $sale)
        ->assertStatus(409)
        ->assertJsonPath('redirect', route('pos.authority-ended'));

    expect($sale->refresh()->status)->toBe(SaleStatus::Invoiced);
});

test('voiding a waiting invoice releases the stock, keeps the number and offers the cart back', function () {
    $urea = ureaInStock();
    $first = invoiceAtCounter($this->pos, [['product_id' => $urea->id, 'unit_id' => $this->bag, 'qty' => '2']], '18000');
    openDrawer($this->pos['main'], $this->pos['owner']);

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->postJson(route('pos.sales.void', $first), ['reason' => 'Customer changed mind'])
        ->assertOk();

    $first->refresh();
    expect($first->status)->toBe(SaleStatus::Void)
        ->and($first->invoice_no)->toBe('INV-'.now()->format('Y').'-000001')
        ->and($first->void_reason)->toBe('Customer changed mind')
        ->and(StockLevel::where('product_id', $urea->id)->value('qty_reserved'))->toBe('0.000');

    $restore = app(LiveCartStore::class)->voidRestores($this->pos['counter']->id);
    expect($restore)->toHaveCount(1)
        ->and($restore[0]['cart']['lines'][0]['qty'])->toBe('2.000');

    // Numbers stay gapless: the next invoice is 000002.
    expect(invoiceAtCounter($this->pos, [['product_id' => $urea->id, 'unit_id' => $this->bag, 'qty' => '2']], '18000')->invoice_no)
        ->toBe('INV-'.now()->format('Y').'-000002');
});

test('voiding a sale settled today puts the stock back and refunds from the drawer', function () {
    $urea = ureaInStock();
    $sale = invoiceAtCounter($this->pos, [['product_id' => $urea->id, 'unit_id' => $this->bag, 'qty' => '2']], '20000');
    $session = openDrawer($this->pos['main'], $this->pos['owner']);
    settleAtMain($this->pos, $this->pos['owner'], $sale)->assertOk();

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->postJson(route('pos.sales.void', $sale), ['reason' => 'Wrong item'])
        ->assertOk();

    expect($sale->refresh()->status)->toBe(SaleStatus::Void)
        ->and(StockLevel::where('product_id', $urea->id)->value('qty_on_hand'))->toBe('1000.000')
        ->and(Payment::where('sale_id', $sale->id)->sum('amount'))->toEqual(0)
        ->and((string) app(DrawerCalculator::class)->expectedCash($session))->toBe('5000.00');
    expectStockMatchesLedger();
});

test('staff cannot void', function () {
    $urea = ureaInStock();
    $sale = invoiceAtCounter($this->pos, [['product_id' => $urea->id, 'unit_id' => $this->bag, 'qty' => '1']], '9000');

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->postJson(route('pos.sales.void', $sale), ['reason' => 'x'])
        ->assertForbidden();
});

test('the day cannot be closed while an invoice waits for settlement', function () {
    $urea = ureaInStock();
    $sale = invoiceAtCounter($this->pos, [['product_id' => $urea->id, 'unit_id' => $this->bag, 'qty' => '1']], '9000');
    $session = openDrawer($this->pos['main'], $this->pos['owner']);

    expect(fn () => app(CloseDrawerAction::class)->handle($session, $this->pos['owner'], ['1000' => 5], DrawerCloseReason::EndOfDay))
        ->toThrow(ValidationException::class, $sale->invoice_no);

    settleAtMain($this->pos, $this->pos['owner'], $sale)->assertOk();

    $closed = app(CloseDrawerAction::class)->handle($session, $this->pos['owner'], ['5000' => 2, '2000' => 2], DrawerCloseReason::EndOfDay);
    expect($closed->expected_cash)->toBe('14000.00')
        ->and($closed->counted_cash)->toBe('14000.00')
        ->and($closed->variance)->toBe('0.00')
        ->and($closed->is_open)->toBeNull();
});

test('find an invoice by its last digits', function () {
    $urea = ureaInStock();
    $sale = invoiceAtCounter($this->pos, [['product_id' => $urea->id, 'unit_id' => $this->bag, 'qty' => '1']], '9000');
    openDrawer($this->pos['main'], $this->pos['owner']);

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->getJson(route('api.pos.sales.find', ['no' => '1']))
        ->assertOk()
        ->assertJsonPath('sale.id', $sale->id)
        ->assertJsonMissingPath('sale.cost_total');
});
