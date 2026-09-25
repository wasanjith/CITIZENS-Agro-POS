<?php

use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerLedgerEntry;
use App\Domain\Identity\Actions\CreateDelegationAction;
use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\CounterEvent;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

beforeEach(function () {
    $this->pos = posSetup();
    $this->urea = ureaInStock();
    $this->bag = unitId('bag');
});

function ureaBags(TestCase $test, string $bags): array
{
    return [['product_id' => $test->urea->id, 'unit_id' => $test->bag, 'qty' => $bags]];
}

test('a credit invoice needs a customer', function () {
    expect(fn () => counterInvoice($this->pos, ureaBags($this, '1'), ['payment_method' => 'credit']))
        ->toThrow(ValidationException::class, 'Choose the customer (F4) for a credit sale.');
});

test('an inactive customer cannot be put on a bill', function () {
    $customer = Customer::factory()->inactive()->create();

    expect(fn () => counterInvoice($this->pos, ureaBags($this, '1'), ['customer_id' => $customer->id, 'payment_method' => 'credit']))
        ->toThrow(ValidationException::class);
});

test('credit sale within the limit: the ledger is debited with a due date, the invoice owes its total', function () {
    $customer = creditCustomer('50000', ['credit_days' => 45]);
    $sale = counterInvoice($this->pos, ureaBags($this, '2'), ['customer_id' => $customer->id, 'payment_method' => 'credit']);

    expect($sale->customer_id)->toBe($customer->id)
        ->and($sale->tendered_amount)->toBeNull()
        ->and($sale->payment_method_intent)->toBe(PaymentMethod::Credit);

    $session = openDrawer($this->pos['main'], $this->pos['owner']);
    cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk()->assertJsonPath('open_drawer', false);

    $sale->refresh();
    expect($sale->status)->toBe(SaleStatus::Settled)
        ->and($sale->balance_due)->toBe('18000.00')
        ->and($sale->due_date->toDateString())->toBe(today()->addDays(45)->toDateString());

    $entry = CustomerLedgerEntry::sole();
    expect($entry->type)->toBe(CustomerLedgerType::Sale)
        ->and($entry->debit)->toBe('18000.00')
        ->and($entry->reference)->toBe($sale->invoice_no)
        ->and($entry->due_date->toDateString())->toBe($sale->due_date->toDateString());

    expect((string) $customer->balance())->toBe('18000.00')
        ->and((string) $customer->availableCredit())->toBe('32000.00')
        ->and(Payment::sole()->method)->toBe(PaymentMethod::Credit)
        // Credit is not money in the drawer.
        ->and((string) app(DrawerCalculator::class)->expectedCash($session))->toBe('5000.00');
    expectStockMatchesLedger();
});

test('over the credit limit: the owner must tick the override', function () {
    $customer = creditCustomer('0');
    $sale = counterInvoice($this->pos, ureaBags($this, '1'), ['customer_id' => $customer->id, 'payment_method' => 'credit']);
    openDrawer($this->pos['main'], $this->pos['owner']);

    cashierSettle($this->pos, $this->pos['owner'], $sale)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credit_limit' => 'Only the owner can allow it.']);

    cashierSettle($this->pos, $this->pos['owner'], $sale, ['override_credit_limit' => true])->assertOk();
    expect((string) $customer->balance())->toBe('9000.00');
});

test('the delegated Manager cannot go over a limit even with the override flag', function () {
    $customer = creditCustomer('5000');
    $sale = counterInvoice($this->pos, ureaBags($this, '1'), ['customer_id' => $customer->id, 'payment_method' => 'credit']);
    app(CreateDelegationAction::class)->handle($this->pos['owner'], $this->pos['manager'], now()->addHours(3));
    openDrawer($this->pos['main'], $this->pos['manager']);

    cashierSettle($this->pos, $this->pos['manager'], $sale, ['override_credit_limit' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credit_limit');

    expect($sale->refresh()->status)->toBe(SaleStatus::Invoiced)
        ->and(CustomerLedgerEntry::count())->toBe(0);
});

test('the cashier can settle a cash-intent bill with a customer on credit', function () {
    $customer = creditCustomer();
    $sale = counterInvoice($this->pos, ureaBags($this, '1'), ['customer_id' => $customer->id, 'tendered' => '9000']);
    openDrawer($this->pos['main'], $this->pos['owner']);

    cashierSettle($this->pos, $this->pos['owner'], $sale, ['method' => 'credit'])->assertOk();

    expect($sale->refresh()->payment_method_intent)->toBe(PaymentMethod::Credit)
        ->and($sale->balance_due)->toBe('9000.00')
        ->and((string) $customer->balance())->toBe('9000.00');
});

test('credit is not offered for a bill without a customer at settlement', function () {
    $sale = counterInvoice($this->pos, ureaBags($this, '1'), ['tendered' => '9000']);
    openDrawer($this->pos['main'], $this->pos['owner']);

    cashierSettle($this->pos, $this->pos['owner'], $sale, ['method' => 'credit'])->assertUnprocessable()->assertJsonValidationErrors('method');
});

test('voiding a settled credit sale the same day takes it off the account', function () {
    $customer = creditCustomer();
    $sale = counterInvoice($this->pos, ureaBags($this, '1'), ['customer_id' => $customer->id, 'payment_method' => 'credit']);
    openDrawer($this->pos['main'], $this->pos['owner']);
    cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk();

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->postJson(route('pos.sales.void', $sale), ['reason' => 'Wrong customer'])
        ->assertOk();

    expect($sale->refresh()->status)->toBe(SaleStatus::Void)
        ->and($sale->balance_due)->toBe('0.00')
        ->and((string) $customer->balance())->toBe('0.00')
        ->and(CustomerLedgerEntry::where('type', CustomerLedgerType::Adjustment)->value('credit'))->toBe('9000.00');
    expectStockMatchesLedger();
});

test('the printed credit invoice shows the customer and the CREDIT mark', function () {
    $customer = creditCustomer('50000', ['name' => 'Sunil Perera', 'name_si' => 'සුනිල් පෙරේරා']);
    $sale = counterInvoice($this->pos, ureaBags($this, '1'), ['customer_id' => $customer->id, 'payment_method' => 'credit']);

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->get(route('pos.sales.invoice', $sale))
        ->assertOk()
        ->assertSee('සුනිල් පෙරේරා')
        ->assertSee('ණය ඉන්වොයිසිය')
        ->assertSee($customer->code);
});

test('cart sync records who the customer is and keeps it for Live Billing', function () {
    $customer = creditCustomer('50000', ['name' => 'Kamal']);
    $uuid = (string) Str::uuid();

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->postJson(route('api.pos.cart.sync'), cartPayload(ureaBags($this, '1'), ['cart_uuid' => $uuid, 'customer_id' => $customer->id]))
        ->assertOk()
        ->assertJsonPath('cart.customer.name', 'Kamal');

    expect(CounterEvent::where('type', CounterEventType::CustomerSet)->where('cart_uuid', $uuid)->exists())->toBeTrue();
});

test('holding and recalling a bill keeps the customer', function () {
    $customer = creditCustomer();

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->postJson(route('api.pos.holds.store'), cartPayload(ureaBags($this, '1'), ['customer_id' => $customer->id]))
        ->assertSuccessful();

    $held = Sale::where('status', SaleStatus::OnHold)->sole();

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->postJson(route('api.pos.holds.recall', $held))
        ->assertOk()
        ->assertJsonPath('cart.customer_id', $customer->id);
});

test('the customer balance always equals debits minus credits, and unpaid invoices add up to it', function () {
    $customer = creditCustomer();
    openDrawer($this->pos['main'], $this->pos['owner']);

    foreach (['1', '2'] as $bags) {
        $sale = counterInvoice($this->pos, ureaBags($this, $bags), ['customer_id' => $customer->id, 'payment_method' => 'credit']);
        cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk();
    }

    $debits = (float) CustomerLedgerEntry::sum('debit');
    $credits = (float) CustomerLedgerEntry::sum('credit');

    expect((float) (string) $customer->balance())->toBe($debits - $credits)
        ->and((float) $customer->openCreditSales()->sum('balance_due'))->toBe(27000.0);
});

test('settling a credit sale prints a credit bill on the main printer for the customer signature and shop seal', function () {
    $customer = creditCustomer('50000', ['name' => 'Sunil Perera', 'name_si' => 'සුනිල් පෙරේරා', 'nic' => '881234567V']);
    $sale = counterInvoice($this->pos, ureaBags($this, '1'), ['customer_id' => $customer->id, 'payment_method' => 'credit']);
    openDrawer($this->pos['main'], $this->pos['owner']);
    $key = Str::random(32);

    $url = atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->postJson(route('api.pos.sales.settle', $sale), ['idempotency_key' => $key])
        ->assertOk()
        ->json('credit_bill_url');

    $job = PrintJob::where('document_type', PrintDocumentType::CreditBill)->sole();
    expect($url)->toContain('job='.$job->id)
        ->and($job->terminal_id)->toBe($this->pos['main']->id)
        ->and($job->is_copy)->toBeFalse();

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->get($url)
        ->assertOk()
        ->assertSee('ණය බිල්පත')
        ->assertSee('සුනිල් පෙරේරා')
        ->assertSee('881234567V')
        ->assertSee('පාරිභෝගිකයාගේ අත්සන')
        ->assertSee('ආයතන මුද්‍රාව')
        ->assertSee('9,000.00')
        ->assertSee('window.print()', false);

    // Retried settle: no second credit bill.
    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->postJson(route('api.pos.sales.settle', $sale), ['idempotency_key' => $key])
        ->assertOk()
        ->assertJsonPath('credit_bill_url', null);
    expect(PrintJob::where('document_type', PrintDocumentType::CreditBill)->count())->toBe(1);

    // Reprint from the invoice page is marked COPY.
    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->post(route('pos.sales.credit-bill.reprint', $sale))
        ->assertRedirect()
        ->assertSessionHas('print_url');
    expect(PrintJob::where('document_type', PrintDocumentType::CreditBill)->where('is_copy', true)->count())->toBe(1);
});

test('cash sales print no credit bill', function () {
    $sale = counterInvoice($this->pos, ureaBags($this, '1'), ['tendered' => '9000']);
    openDrawer($this->pos['main'], $this->pos['owner']);

    cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk()->assertJsonPath('credit_bill_url', null);
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->get(route('pos.sales.credit-bill', $sale))->assertNotFound();
});
