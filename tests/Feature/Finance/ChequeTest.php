<?php

use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Models\CustomerPayment;
use App\Domain\Finance\Enums\ChequeDirection;
use App\Domain\Finance\Enums\ChequeStatus;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Jobs\ChequesDueReminderJob;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Finance\Notifications\ChequesDueAlert;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Domain\Sales\Models\Sale;
use App\Domain\System\Services\Settings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pos = posSetup();
    $this->urea = ureaInStock();
    $this->session = openDrawer($this->pos['main'], $this->pos['owner']);
    $this->bank = bankAccount(['opening_balance' => '100000.00']);
    $this->customer = creditCustomer('100000');

    // Two credit invoices: 1 bag (9 000) and 2 bags (18 000).
    $this->sales = collect(['1', '2'])->map(function (string $bags) {
        $sale = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => $bags]], ['customer_id' => $this->customer->id, 'payment_method' => 'credit']);
        cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk();

        return $sale->refresh();
    });
});

afterEach(function () {
    expectBooksBalance();
});

function payByCheque(array $pos, $customer, string $amount, string $chequeDate): CustomerPayment
{
    atTerminal($pos['mainToken'], $pos['owner'])->post(route('pos.customer-payments.store', $customer), [
        'amount' => $amount,
        'method' => 'cheque',
        'reference' => '451122',
        'cheque_bank' => 'Sampath Bank',
        'cheque_branch' => 'Anuradhapura',
        'cheque_date' => $chequeDate,
        'allocation_mode' => 'fifo',
        'idempotency_key' => Str::random(32),
    ])->assertSessionHasNoErrors();

    return CustomerPayment::query()->latest('id')->firstOrFail();
}

test('a customer cheque is registered with its details, deposited and cleared into the bank', function () {
    $payment = payByCheque($this->pos, $this->customer, '27000', today()->toDateString());
    $cheque = Cheque::sole();

    expect($cheque->direction)->toBe(ChequeDirection::Received)
        ->and($cheque->bank_name)->toBe('Sampath Bank')
        ->and($cheque->source->is($payment))->toBeTrue()
        ->and($this->customer->openCreditSales()->count())->toBe(0);

    $this->actingAs($this->pos['owner'])->post(route('finance.cheques.deposit', $cheque), ['bank_account_id' => $this->bank->id, 'date' => today()->toDateString()])->assertSessionHasNoErrors();
    expect($cheque->refresh()->status)->toBe(ChequeStatus::Deposited)
        ->and((string) $this->bank->balance())->toBe('100000.00');

    $this->actingAs($this->pos['owner'])->post(route('finance.cheques.clear', $cheque), ['date' => today()->toDateString()])->assertSessionHasNoErrors();
    expect($cheque->refresh()->status)->toBe(ChequeStatus::Cleared)
        ->and((string) $this->bank->balance())->toBe('127000.00')
        ->and((string) app(ChartOfAccounts::class)->get(SystemAccount::ChequesInHand)->balance())->toBe('0.00');
});

test('a post-dated cheque cannot be deposited before its date', function () {
    payByCheque($this->pos, $this->customer, '9000', today()->addDays(10)->toDateString());

    $this->actingAs($this->pos['owner'])
        ->post(route('finance.cheques.deposit', Cheque::sole()), ['bank_account_id' => $this->bank->id, 'date' => today()->toDateString()])
        ->assertSessionHasErrors('date');

    expect(Cheque::sole()->status)->toBe(ChequeStatus::Pending);
});

test('a bounced cheque reverses the customer payment and restores the receivable and the invoices', function () {
    payByCheque($this->pos, $this->customer, '27000', today()->toDateString());
    $cheque = Cheque::sole();
    $this->actingAs($this->pos['owner'])->post(route('finance.cheques.deposit', $cheque), ['bank_account_id' => $this->bank->id, 'date' => today()->toDateString()]);

    $this->actingAs($this->pos['owner'])
        ->post(route('finance.cheques.bounce', $cheque), ['date' => today()->toDateString(), 'reason' => 'Insufficient funds'])
        ->assertSessionHasNoErrors();

    expect($cheque->refresh()->status)->toBe(ChequeStatus::Bounced)
        ->and(CustomerPayment::sole()->reversed_at)->not->toBeNull()
        ->and((string) $this->customer->balance())->toBe('27000.00')
        ->and($this->sales->map(fn (Sale $sale) => $sale->refresh()->balance_due)->all())->toBe(['9000.00', '18000.00'])
        ->and($this->customer->ledger()->where('type', CustomerLedgerType::ChequeBounced)->value('debit'))->toBe('27000.00')
        ->and((string) app(ChartOfAccounts::class)->get(SystemAccount::AccountsReceivable)->balance())->toBe('27000.00');
});

test('a cheque that bounces after clearing is taken back out of the bank', function () {
    payByCheque($this->pos, $this->customer, '9000', today()->toDateString());
    $cheque = Cheque::sole();
    $owner = $this->actingAs($this->pos['owner']);
    $owner->post(route('finance.cheques.deposit', $cheque), ['bank_account_id' => $this->bank->id, 'date' => today()->toDateString()]);
    $owner->post(route('finance.cheques.clear', $cheque), ['date' => today()->toDateString()]);
    $owner->post(route('finance.cheques.bounce', $cheque), ['date' => today()->toDateString(), 'reason' => 'Returned by bank'])->assertSessionHasNoErrors();

    expect((string) $this->bank->balance())->toBe('100000.00')
        ->and((string) $this->customer->balance())->toBe('27000.00');
});

test('a walk-in cheque sale that bounces goes to dishonoured cheques', function () {
    $sale = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['payment_method' => 'cheque']);
    cashierSettle($this->pos, $this->pos['owner'], $sale, ['method' => 'cheque', 'reference' => '777'])->assertOk();

    $this->actingAs($this->pos['owner'])
        ->post(route('finance.cheques.bounce', Cheque::sole()), ['date' => today()->toDateString(), 'reason' => 'Account closed'])
        ->assertSessionHasNoErrors();

    expect((string) app(ChartOfAccounts::class)->get(SystemAccount::DishonouredCheques)->balance())->toBe('9000.00');
});

test('a supplier paid by cheque: the cheque clears from the bank; a cancelled cheque makes the goods receipt unpaid again', function () {
    $supplier = Supplier::factory()->create();
    $receipt = directGrn($this->pos['owner'], $supplier, $this->urea, '2', '8500');

    $pay = fn (string $number) => $this->actingAs($this->pos['owner'])->post(route('purchasing.supplier-payments.store', $supplier), [
        'amount' => '17000', 'date' => today()->toDateString(), 'paid_from' => 'cheque', 'bank_account_id' => $this->bank->id,
        'cheque_number' => $number, 'cheque_date' => today()->addDays(7)->toDateString(), 'allocation_mode' => 'fifo',
    ])->assertSessionHasNoErrors();

    $pay('900001');
    $first = Cheque::where('direction', ChequeDirection::Issued)->sole();
    expect($receipt->refresh()->amount_paid)->toBe('17000.00')
        ->and((string) $supplier->balance())->toBe('0.00')
        ->and((string) app(ChartOfAccounts::class)->get(SystemAccount::ChequesIssued)->balance())->toBe('17000.00');

    $this->actingAs($this->pos['owner'])->post(route('finance.cheques.cancel', $first), ['date' => today()->toDateString(), 'reason' => 'Wrong amount written'])->assertSessionHasNoErrors();
    expect($first->refresh()->status)->toBe(ChequeStatus::Cancelled)
        ->and($receipt->refresh()->amount_paid)->toBe('0.00')
        ->and((string) $supplier->balance())->toBe('17000.00')
        ->and(SupplierPayment::sole()->reversed_at)->not->toBeNull();

    $pay('900002');
    $second = Cheque::where('number', '900002')->sole();
    $this->actingAs($this->pos['owner'])->post(route('finance.cheques.clear', $second), ['date' => today()->toDateString()])->assertSessionHasNoErrors();

    expect((string) $this->bank->balance())->toBe('83000.00')
        ->and($receipt->refresh()->amount_paid)->toBe('17000.00')
        ->and(GoodsReceipt::query()->whereColumn('amount_paid', '<', 'total')->count())->toBe(0);
});

test('the morning reminder lists cheques to deposit and issued cheques falling due', function () {
    Notification::fake();
    payByCheque($this->pos, $this->customer, '9000', today()->toDateString());

    (new ChequesDueReminderJob)->handle(app(Settings::class));

    Notification::assertSentTo($this->pos['owner'], ChequesDueAlert::class, fn (ChequesDueAlert $alert) => $alert->toDeposit === 1 && $alert->toDepositAmount === '9000.00');
    Notification::assertNotSentTo($this->pos['manager'], ChequesDueAlert::class);
});

test('the cheque calendar and register show the cheques', function () {
    payByCheque($this->pos, $this->customer, '9000', today()->addDays(3)->toDateString());

    $this->actingAs($this->pos['owner'])->get(route('finance.cheques.calendar'))->assertOk()->assertSee($this->customer->name);
    $this->actingAs($this->pos['owner'])->get(route('finance.cheques.index', ['filter' => ['status' => 'open']]))->assertOk()->assertSee('451122');
    $this->actingAs($this->pos['owner'])->get(route('finance.cheques.show', Cheque::sole()))->assertOk()->assertSee('Sampath Bank', false);
});
