<?php

use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerLedgerEntry;
use App\Domain\Customers\Models\CustomerPayment;
use App\Domain\Customers\Models\CustomerPaymentAllocation;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Support\Money;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pos = posSetup();
    $this->urea = ureaInStock();
    $this->customer = creditCustomer('100000');
    $this->session = openDrawer($this->pos['main'], $this->pos['owner']);

    // Three credit invoices of 1, 2 and 3 bags (9 000, 18 000, 27 000), due in that order.
    $this->sales = collect(['1', '2', '3'])->map(function (string $bags, int $index) {
        $sale = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => $bags]], ['customer_id' => $this->customer->id, 'payment_method' => 'credit']);
        cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk();
        $sale->refresh()->forceFill(['due_date' => today()->subDays(30 - $index * 10)])->save();

        return $sale->refresh();
    });
});

function receive(array $pos, Customer $customer, array $data, ?string $key = null)
{
    return atTerminal($pos['mainToken'], $pos['owner'])->post(route('pos.customer-payments.store', $customer), [
        'method' => 'cash',
        'allocation_mode' => 'fifo',
        'idempotency_key' => $key ?? Str::random(32),
        ...$data,
    ]);
}

test('a payment is applied oldest invoice first, lowers what each invoice owes and credits the ledger', function () {
    receive($this->pos, $this->customer, ['amount' => '20000'])->assertRedirect()->assertSessionHas('print_url');

    [$first, $second, $third] = $this->sales->map->refresh();
    expect($first->balance_due)->toBe('0.00')
        ->and($second->balance_due)->toBe('7000.00')
        ->and($third->balance_due)->toBe('27000.00');

    $payment = CustomerPayment::sole();
    expect($payment->number)->toStartWith('RCP-')
        ->and($payment->allocations->pluck('amount', 'sale_id')->all())->toBe([$first->id => '9000.00', $second->id => '11000.00'])
        ->and(CustomerLedgerEntry::where('type', CustomerLedgerType::Payment)->value('credit'))->toBe('20000.00')
        ->and((string) $this->customer->balance())->toBe('34000.00')
        ->and((string) $this->customer->balance())->toBe((string) Money::of((string) $this->customer->openCreditSales()->sum('balance_due')));

    expect(PrintJob::where('document_type', PrintDocumentType::PaymentReceipt)->where('document_id', $payment->id)->exists())->toBeTrue();
});

test('cash from a customer goes into the drawer; a card payment does not and needs a reference', function () {
    receive($this->pos, $this->customer, ['amount' => '5000'])->assertRedirect();
    expect((string) app(DrawerCalculator::class)->expectedCash($this->session))->toBe('10000.00');

    receive($this->pos, $this->customer, ['amount' => '3000', 'method' => 'card'])->assertSessionHasErrors('reference');
    receive($this->pos, $this->customer, ['amount' => '3000', 'method' => 'card', 'reference' => 'SLIP-9'])->assertRedirect();

    $summary = app(DrawerCalculator::class)->summary($this->session);
    expect($summary['expected_cash'])->toBe('10000.00')
        ->and($summary['customer_cash'])->toBe('5000.00')
        ->and($summary['customer_payments']['card']['amount'])->toBe('3000.00');
});

test('the cashier can choose which invoices a payment pays', function () {
    $third = $this->sales[2];

    receive($this->pos, $this->customer, ['amount' => '10000', 'allocation_mode' => 'manual', 'allocations' => [$third->id => '10000']])->assertRedirect();

    expect($third->refresh()->balance_due)->toBe('17000.00')
        ->and($this->sales[0]->refresh()->balance_due)->toBe('9000.00');
});

test('manual amounts cannot exceed what an invoice owes or the payment', function () {
    $first = $this->sales[0];

    receive($this->pos, $this->customer, ['amount' => '20000', 'allocation_mode' => 'manual', 'allocations' => [$first->id => '9500']])
        ->assertSessionHasErrors("allocations.{$first->id}");
    receive($this->pos, $this->customer, ['amount' => '5000', 'allocation_mode' => 'manual', 'allocations' => [$first->id => '9000']])
        ->assertSessionHasErrors('allocations');

    // Someone else's invoice.
    $other = creditCustomer();
    $sale = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['customer_id' => $other->id, 'payment_method' => 'credit']);
    cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk();
    receive($this->pos, $this->customer, ['amount' => '1000', 'allocation_mode' => 'manual', 'allocations' => [$sale->id => '1000']])
        ->assertSessionHasErrors('allocations');

    expect(CustomerPayment::count())->toBe(0);
});

test('paying more than is owed leaves an advance on the account', function () {
    receive($this->pos, $this->customer, ['amount' => '60000'])->assertRedirect();

    expect($this->sales->map->refresh()->sum('balance_due'))->toEqual(0)
        ->and((string) $this->customer->balance())->toBe('-6000.00')
        ->and(CustomerPaymentAllocation::sum('amount'))->toEqual(54000);
});

test('the same payment sent twice is recorded once', function () {
    $key = Str::random(32);

    receive($this->pos, $this->customer, ['amount' => '1000'], $key)->assertRedirect();
    receive($this->pos, $this->customer, ['amount' => '1000'], $key)->assertRedirect();

    expect(CustomerPayment::count())->toBe(1)
        ->and(CustomerLedgerEntry::where('type', CustomerLedgerType::Payment)->count())->toBe(1);
});

test('payments are only taken by the drawer holder on the main terminal', function () {
    atTerminal($this->pos['mainToken'], $this->pos['staff'])
        ->post(route('pos.customer-payments.store', $this->customer), ['amount' => '100', 'method' => 'cash', 'allocation_mode' => 'fifo', 'idempotency_key' => Str::random(32)])
        ->assertForbidden();

    atTerminal($this->pos['counterToken'], $this->pos['owner'])
        ->post(route('pos.customer-payments.store', $this->customer), ['amount' => '100', 'method' => 'cash', 'allocation_mode' => 'fifo', 'idempotency_key' => Str::random(32)])
        ->assertForbidden();

    expect(CustomerPayment::count())->toBe(0);
});

test('the payment receipt prints in Sinhala with the invoices paid and the new balance', function () {
    receive($this->pos, $this->customer, ['amount' => '10000'])->assertRedirect();
    $payment = CustomerPayment::sole();
    $job = PrintJob::where('document_type', PrintDocumentType::PaymentReceipt)->sole();

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->get(route('pos.customer-payments.receipt', ['customerPayment' => $payment, 'job' => $job->id]))
        ->assertOk()
        ->assertSee('ගෙවීම් රිසිට්පත')
        ->assertSee($this->sales[0]->invoice_no)
        ->assertSee('54,000.00')
        ->assertSee('44,000.00')
        ->assertSee('window.print()', false);
});

test('the payment form and pages render', function () {
    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->get(route('pos.customer-payments.create', ['customer' => $this->customer->id]))
        ->assertOk()
        ->assertSee($this->sales[0]->invoice_no);

    receive($this->pos, $this->customer, ['amount' => '1000'])->assertRedirect();
    $payment = CustomerPayment::sole();

    $this->actingAs($this->pos['owner'])->get(route('customers.payments.index'))->assertOk()->assertSee($payment->number);
    $this->actingAs($this->pos['owner'])->get(route('customers.payments.show', $payment))->assertOk()->assertSee($this->sales[0]->invoice_no)->assertDontSee('Kept as advance');
    $this->actingAs($this->pos['staff'])->get(route('customers.payments.show', $payment))->assertOk();
});

test('sale pages show what is still owed and the payments applied', function () {
    receive($this->pos, $this->customer, ['amount' => '5000'])->assertRedirect();

    $this->actingAs($this->pos['owner'])
        ->get(route('sales.show', $this->sales[0]))
        ->assertOk()
        ->assertSee('Still owed')
        ->assertSee('4,000.00')
        ->assertSee(CustomerPayment::sole()->number);
});

test('the drawer report shows customer payments', function () {
    receive($this->pos, $this->customer, ['amount' => '2500'])->assertRedirect();

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->get(route('pos.drawer.report', $this->session))
        ->assertOk()
        ->assertSee('පාරිභෝගික ගෙවීම්')
        ->assertSee('2,500.00');
});

test('sales totals are unchanged by payments: the ledger matches unpaid invoices', function () {
    receive($this->pos, $this->customer, ['amount' => '12345.50'])->assertRedirect();

    expect((string) $this->customer->balance())->toBe(number_format(54000 - 12345.50, 2, '.', ''))
        ->and((float) Sale::sum('balance_due'))->toBe(54000 - 12345.50);
});
