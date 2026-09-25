<?php

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Sales\Enums\ApprovalStatus;
use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\ApprovalRequest;
use App\Domain\Sales\Models\CounterEvent;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Services\LiveCartStore;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pos = posSetup();
    $this->urea = ureaInStock();
    $this->bag = unitId('bag');
    $this->kg = unitId('kg');
});

function issueAtCounter(array $pos, array $cart, ?string $key = null)
{
    return atTerminal($pos['counterToken'], $pos['staff'])
        ->postJson(route('api.pos.invoices.store'), [...$cart, 'idempotency_key' => $key ?? Str::random(32)]);
}

test('printing at the counter numbers the invoice, reserves stock and creates the print job', function () {
    $cart = cartPayload(
        [['product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '2']],
        ['tendered' => '20000'],
    );

    $response = issueAtCounter($this->pos, $cart)->assertCreated();

    $sale = Sale::sole();
    expect($sale->invoice_no)->toBe('INV-'.now()->format('Y').'-000001')
        ->and($sale->status)->toBe(SaleStatus::Invoiced)
        ->and($sale->total)->toBe('18000.00')
        ->and($sale->change_due)->toBe('2000.00')
        ->and($sale->invoiced_terminal_id)->toBe($this->pos['counter']->id)
        ->and($sale->items->sole()->base_qty)->toBe('100.000');

    $level = StockLevel::where('product_id', $this->urea->id)->sole();
    expect($level->qty_on_hand)->toBe('1000.000')->and($level->qty_reserved)->toBe('100.000');

    $job = PrintJob::sole();
    expect($job->is_copy)->toBeFalse()->and($job->terminal_id)->toBe($this->pos['counter']->id);
    $response->assertJsonPath('print_url', route('pos.sales.invoice', ['sale' => $sale, 'job' => $job->id]));

    expect(CounterEvent::where('type', CounterEventType::Printed)->where('sale_id', $sale->id)->exists())->toBeTrue()
        ->and(app(LiveCartStore::class)->get($this->pos['counter']->id))->toBeNull();

    // The response carries no cost.
    expect($response->json('sale'))->not->toHaveKey('cost_total');
});

test('the same idempotency key gives one invoice', function () {
    $cart = cartPayload([['product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '1']], ['tendered' => '9000']);
    $key = Str::random(32);

    issueAtCounter($this->pos, $cart, $key)->assertCreated();
    issueAtCounter($this->pos, $cart, $key)->assertOk()->assertJsonPath('created', false);

    expect(Sale::count())->toBe(1)
        ->and(StockLevel::where('product_id', $this->urea->id)->value('qty_reserved'))->toBe('50.000');
});

test('a cash invoice with less tendered than the total is rejected', function () {
    $cart = cartPayload([['product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '1']], ['tendered' => '8999.99']);

    issueAtCounter($this->pos, $cart)->assertUnprocessable()->assertJsonValidationErrors('tendered');

    expect(Sale::count())->toBe(0);
});

test('prices sent by the counter are ignored: the server reprices every line', function () {
    $cart = cartPayload(
        [['product_id' => $this->urea->id, 'unit_id' => $this->kg, 'qty' => '2.5', 'unit_price' => '1.00', 'line_total' => '2.50']],
        ['tendered' => '1000', 'total' => '2.50', 'subtotal' => '2.50'],
    );

    issueAtCounter($this->pos, $cart)->assertCreated();

    $sale = Sale::sole();
    expect($sale->total)->toBe('475.00')
        ->and($sale->items->sole()->unit_price)->toBe('190.00')
        ->and($sale->change_due)->toBe('525.00');
});

test('stock that is not available cannot be invoiced', function () {
    $cart = cartPayload([['product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '21']], ['tendered' => '200000']);

    issueAtCounter($this->pos, $cart)->assertUnprocessable()->assertJsonValidationErrors('stock');

    expect(Sale::count())->toBe(0)
        ->and(StockLevel::where('product_id', $this->urea->id)->value('qty_reserved'))->toBe('0.000');
});

test('reserved stock is not available to the next counter', function () {
    issueAtCounter($this->pos, cartPayload([['product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '15']], ['tendered' => '135000']))->assertCreated();

    issueAtCounter($this->pos, cartPayload([['product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '6']], ['tendered' => '54000']))
        ->assertUnprocessable()->assertJsonValidationErrors('stock');
});

test('a discount above the staff limit blocks printing until the cashier approves it', function () {
    $cartUuid = (string) Str::uuid();
    // Staff limit is 5 %; 1000 on 9000 is 11 %.
    $line = ['key' => 'urea-bag', 'product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '1', 'discount' => '1000'];

    issueAtCounter($this->pos, cartPayload([$line], ['cart_uuid' => $cartUuid, 'tendered' => '8000']))
        ->assertUnprocessable()->assertJsonValidationErrors('discount');

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->postJson(route('api.pos.approvals.store'), ['cart_uuid' => $cartUuid, 'scope' => 'line', 'line_key' => 'urea-bag', 'label' => 'Urea', 'amount' => '1000', 'gross' => '9000'])
        ->assertCreated();

    $approval = ApprovalRequest::sole();
    expect($approval->status)->toBe(ApprovalStatus::Pending);

    openDrawer($this->pos['main'], $this->pos['owner']);
    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->postJson(route('api.pos.approvals.approve', $approval))
        ->assertOk();

    issueAtCounter($this->pos, cartPayload([[...$line, 'approval_request_id' => $approval->id]], ['cart_uuid' => $cartUuid, 'tendered' => '8000']))
        ->assertCreated();

    expect(Sale::sole()->total)->toBe('8000.00')
        ->and($approval->refresh()->sale_id)->toBe(Sale::sole()->id);
});

test('a discount within the staff limit needs no approval', function () {
    $line = ['product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '1', 'discount' => '450'];

    issueAtCounter($this->pos, cartPayload([$line], ['tendered' => '8550']))->assertCreated();
});

test('a reprint is counted, logged and printed as a copy', function () {
    issueAtCounter($this->pos, cartPayload([['product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '1']], ['tendered' => '9000']))->assertCreated();
    $sale = Sale::sole();

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->postJson(route('api.pos.sales.reprint', $sale))
        ->assertOk();

    expect($sale->refresh()->print_count)->toBe(2)
        ->and(PrintJob::where('is_copy', true)->count())->toBe(1)
        ->and(CounterEvent::where('type', CounterEventType::Reprinted)->count())->toBe(1);
});

test('a held bill has no number and reserves nothing; recalling it gives the lines back', function () {
    $cart = cartPayload([['product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '3']]);

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->postJson(route('api.pos.holds.store'), $cart)
        ->assertCreated();

    $held = Sale::sole();
    expect($held->status)->toBe(SaleStatus::OnHold)
        ->and($held->invoice_no)->toBeNull()
        ->and(StockLevel::where('product_id', $this->urea->id)->value('qty_reserved'))->toBe('0.000');

    $response = atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->postJson(route('api.pos.holds.recall', $held))
        ->assertOk();

    expect($response->json('cart.lines.0.qty'))->toBe('3.000')
        ->and(Sale::count())->toBe(0)
        ->and(CounterEvent::where('type', CounterEventType::Recalled)->count())->toBe(1);
});

test('the counter screen needs a registered terminal', function () {
    $this->actingAs($this->pos['staff'])->get(route('pos.counter'))->assertRedirect(route('terminal.unregistered'));

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->get(route('pos.counter'))
        ->assertOk()
        ->assertSee('posCounter', false);
});
