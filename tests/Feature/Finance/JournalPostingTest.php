<?php

use App\Domain\CashDrawer\Actions\CloseDrawerAction;
use App\Domain\CashDrawer\Enums\DrawerCloseReason;
use App\Domain\Finance\Enums\ChequeDirection;
use App\Domain\Finance\Enums\ChequeStatus;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Exceptions\UnbalancedJournalException;
use App\Domain\Finance\Models\BankTransaction;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Finance\Services\JournalService;
use App\Domain\Inventory\Actions\CreateStockAdjustmentAction;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierReturn;
use App\Domain\Sales\Models\Sale;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pos = posSetup();
    $this->urea = ureaInStock();
    $this->session = openDrawer($this->pos['main'], $this->pos['owner']);
});

afterEach(function () {
    expectBooksBalance();
});

function balanceOf(SystemAccount $account): string
{
    return (string) app(ChartOfAccounts::class)->get($account)->balance();
}

function journalUreaBags(string $bags): array
{
    return [['product_id' => test()->urea->id, 'unit_id' => unitId('bag'), 'qty' => $bags]];
}

function settledSale(array $lines, array $extra = [], array $settle = []): Sale
{
    $sale = counterInvoice(test()->pos, $lines, ['tendered' => '100000', ...$extra]);
    cashierSettle(test()->pos, test()->pos['owner'], $sale, $settle)->assertOk();

    return $sale->refresh();
}

test('opening the drawer moves the float out of the safe', function () {
    $entry = JournalEntry::where('event', 'drawer.opened')->sole();

    expect($entry->source->is($this->session))->toBeTrue()
        ->and(balanceOf(SystemAccount::CashDrawer))->toBe('5000.00')
        ->and(balanceOf(SystemAccount::Safe))->toBe('-5000.00');
});

test('a cash sale posts sales, cost of goods sold and cash into the drawer when it is settled, not when it is printed', function () {
    $sale = counterInvoice($this->pos, journalUreaBags('2'), ['tendered' => '20000']);

    expect(JournalEntry::where('source_type', 'sale')->exists())->toBeFalse();

    cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk();

    $entry = JournalEntry::where('event', 'sale.settled')->sole();
    expect($entry->number)->toStartWith('JE-')
        ->and($entry->lines)->toHaveCount(4)
        ->and(balanceOf(SystemAccount::SalesRevenue))->toBe('18000.00')
        ->and(balanceOf(SystemAccount::CostOfGoodsSold))->toBe('16000.00')
        ->and(balanceOf(SystemAccount::Inventory))->toBe('-16000.00')
        ->and(balanceOf(SystemAccount::CashDrawer))->toBe('23000.00');
});

test('a card sale goes into the bank account marked for card payments, with a bank-book line', function () {
    $bank = bankAccount(['receives_card_payments' => true]);

    settledSale(journalUreaBags('1'), ['payment_method' => 'card'], ['method' => 'card', 'reference' => 'SLIP-1']);

    expect((string) $bank->balance())->toBe('9000.00')
        ->and(BankTransaction::sole()->reference)->toBe('SLIP-1')
        ->and(balanceOf(SystemAccount::CardClearing))->toBe('0.00');
});

test('without a card account the money waits in card & transfer clearing', function () {
    settledSale(journalUreaBags('1'), ['payment_method' => 'bank_transfer'], ['method' => 'bank_transfer', 'reference' => 'TRX-9']);

    expect(balanceOf(SystemAccount::CardClearing))->toBe('9000.00')
        ->and(BankTransaction::count())->toBe(0);
});

test('a cheque sale puts a pending cheque in the register and the money under cheques in hand', function () {
    $customer = creditCustomer();
    $sale = settledSale(journalUreaBags('1'), ['customer_id' => $customer->id, 'payment_method' => 'cheque'], ['method' => 'cheque', 'reference' => '000123']);

    $cheque = Cheque::sole();
    expect($cheque->direction)->toBe(ChequeDirection::Received)
        ->and($cheque->status)->toBe(ChequeStatus::Pending)
        ->and($cheque->number)->toBe('000123')
        ->and($cheque->party->is($customer))->toBeTrue()
        ->and($cheque->source->is($sale))->toBeTrue()
        ->and($sale->payments()->value('cheque_id'))->toBe($cheque->id)
        ->and(balanceOf(SystemAccount::ChequesInHand))->toBe('9000.00');
});

test('a credit sale is a receivable that matches the customer ledger', function () {
    $customer = creditCustomer();
    settledSale(journalUreaBags('2'), ['customer_id' => $customer->id, 'payment_method' => 'credit']);

    expect(balanceOf(SystemAccount::AccountsReceivable))->toBe('18000.00')
        ->and((string) $customer->balance())->toBe('18000.00');
});

test('voiding a settled sale reverses its entry, so nothing is left of it in the accounts', function () {
    $sale = settledSale(journalUreaBags('1'));

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->post(route('pos.sales.void', $sale), ['reason' => 'Wrong item'])
        ->assertSessionHasNoErrors();

    $reversal = JournalEntry::where('event', 'sale.voided')->sole();
    expect($reversal->reverses_id)->toBe(JournalEntry::where('event', 'sale.settled')->value('id'))
        ->and(balanceOf(SystemAccount::SalesRevenue))->toBe('0.00')
        ->and(balanceOf(SystemAccount::Inventory))->toBe('0.00')
        ->and(balanceOf(SystemAccount::CashDrawer))->toBe('5000.00');
});

test('a cash return posts sales returns and puts restocked goods back at cost; damaged goods are a stock loss', function () {
    $sale = settledSale(journalUreaBags('2'));
    $item = $sale->items()->firstOrFail();

    atTerminal($this->pos['mainToken'], $this->pos['owner'])->post(route('pos.returns.store', $sale), [
        'reason' => 'Torn bag',
        'refund_method' => 'cash',
        'lines' => [$item->id => ['qty' => '1', 'restock' => '0']],
        'idempotency_key' => Str::random(32),
    ])->assertSessionHasNoErrors();

    expect(balanceOf(SystemAccount::SalesReturns))->toBe('-9000.00')
        ->and(balanceOf(SystemAccount::CostOfGoodsSold))->toBe('8000.00')
        ->and(balanceOf(SystemAccount::InventoryLoss))->toBe('8000.00')
        ->and(balanceOf(SystemAccount::Inventory))->toBe('-16000.00')
        ->and(balanceOf(SystemAccount::CashDrawer))->toBe('14000.00');
});

test('a customer payment in cash moves the receivable into the drawer', function () {
    $customer = creditCustomer();
    settledSale(journalUreaBags('1'), ['customer_id' => $customer->id, 'payment_method' => 'credit']);

    atTerminal($this->pos['mainToken'], $this->pos['owner'])->post(route('pos.customer-payments.store', $customer), [
        'amount' => '4000', 'method' => 'cash', 'allocation_mode' => 'fifo', 'idempotency_key' => Str::random(32),
    ])->assertSessionHasNoErrors();

    expect(balanceOf(SystemAccount::AccountsReceivable))->toBe('5000.00')
        ->and(balanceOf(SystemAccount::CashDrawer))->toBe('9000.00');
});

test('goods received are inventory owed to the supplier; a supplier return undoes it', function () {
    $supplier = Supplier::factory()->create();
    $receipt = directGrn($this->pos['manager'], $supplier, $this->urea, '2', '8500');

    expect(JournalEntry::where('event', 'grn.posted')->sole()->source->is($receipt))->toBeTrue()
        ->and(balanceOf(SystemAccount::AccountsPayable))->toBe('17000.00')
        ->and(balanceOf(SystemAccount::Inventory))->toBe('17000.00');

    $batch = $receipt->lines()->firstOrFail()->batch;
    $this->actingAs($this->pos['manager'])->post(route('purchasing.supplier-returns.store'), [
        'supplier_id' => $supplier->id,
        'goods_receipt_id' => $receipt->id,
        'return_date' => today()->toDateString(),
        'reason' => 'Wet bags',
        'lines' => [['product_id' => $this->urea->id, 'variant_id' => '', 'batch_id' => $batch->id, 'qty' => '50']],
    ])->assertSessionHasNoErrors();

    // The supplier takes it back at the buying price (8 500 a bag = 170 a kg), while the
    // stock leaves at its average cost (160.91 a kg); the difference is a stock gain.
    $return = SupplierReturn::sole();
    $stockCost = BigDecimal::of('17000.00')->minus(balanceOf(SystemAccount::Inventory));

    expect($return->total)->toBe('8500.00')
        ->and($return->lines()->value('unit_cost'))->toBe('170.0000')
        ->and(balanceOf(SystemAccount::AccountsPayable))->toBe('8500.00')
        ->and((string) $supplier->balance())->toBe('8500.00')
        ->and((string) $stockCost)->toBe('8045.46')
        ->and(balanceOf(SystemAccount::InventoryGain))->toBe('454.54');
});

test('a supplier return without a goods receipt uses the supplier\'s last buying price', function () {
    $supplier = Supplier::factory()->create();
    directGrn($this->pos['manager'], $supplier, $this->urea, '1', '9000');
    $batch = Batch::where('product_id', $this->urea->id)->firstOrFail();

    $this->actingAs($this->pos['manager'])->post(route('purchasing.supplier-returns.store'), [
        'supplier_id' => $supplier->id,
        'goods_receipt_id' => '',
        'return_date' => today()->toDateString(),
        'reason' => 'Caked',
        'lines' => [['product_id' => $this->urea->id, 'variant_id' => '', 'batch_id' => $batch->id, 'qty' => '10']],
    ])->assertSessionHasNoErrors();

    expect(SupplierReturn::sole()->total)->toBe('1800.00')
        ->and((string) $supplier->balance())->toBe('7200.00');
});

test('stock adjustments post gains and losses at batch cost', function () {
    $adjust = app(CreateStockAdjustmentAction::class);
    $batch = Batch::where('product_id', $this->urea->id)->firstOrFail();

    $adjust->handle(['reason' => 'damage', 'note' => 'Rat damage', 'lines' => [['product_id' => $this->urea->id, 'variant_id' => null, 'batch_id' => $batch->id, 'qty' => '-10']]], $this->pos['owner']);
    $adjust->handle(['reason' => 'found', 'note' => 'Found in store', 'lines' => [['product_id' => $this->urea->id, 'variant_id' => null, 'batch_id' => $batch->id, 'qty' => '4']]], $this->pos['owner']);

    expect(balanceOf(SystemAccount::InventoryLoss))->toBe('1600.00')
        ->and(balanceOf(SystemAccount::InventoryGain))->toBe('640.00')
        ->and(balanceOf(SystemAccount::Inventory))->toBe('-960.00');
});

test('closing the drawer short posts the shortage and moves the counted cash to the safe', function () {
    settledSale(journalUreaBags('1'));

    // Expected 14 000, counted 13 900.
    app(CloseDrawerAction::class)->handle($this->session->refresh(), $this->pos['owner'], ['5000' => 2, '1000' => 3, '100' => 9], DrawerCloseReason::EndOfDay);

    expect(balanceOf(SystemAccount::CashShort))->toBe('100.00')
        ->and(balanceOf(SystemAccount::CashDrawer))->toBe('0.00')
        ->and(balanceOf(SystemAccount::Safe))->toBe('8900.00');
});

test('pay in, pay out and safe drop move cash between the drawer, the safe and the owner', function () {
    $movement = fn (string $type, string $amount) => atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->post(route('pos.drawer.movements.store'), ['type' => $type, 'amount' => $amount, 'reason' => 'test'])
        ->assertSessionHasNoErrors();

    $movement('pay_in', '1000');
    $movement('safe_drop', '2000');
    $movement('pay_out', '500');

    expect(balanceOf(SystemAccount::CashDrawer))->toBe('3500.00')
        ->and(balanceOf(SystemAccount::Safe))->toBe('-4000.00')
        ->and(balanceOf(SystemAccount::OwnerDrawings))->toBe('-500.00');
});

test('the journal refuses an entry that does not balance', function () {
    app(JournalService::class)->post('Broken', today(), [
        ['account' => SystemAccount::Safe, 'debit' => '100'],
        ['account' => SystemAccount::OwnerCapital, 'credit' => '99.99'],
    ]);
})->throws(UnbalancedJournalException::class);

test('a customer opening balance and a supplier opening balance are posted against opening balances', function () {
    $this->actingAs($this->pos['owner'])->post(route('customers.store'), [
        'name' => 'Sunil Perera', 'phone' => '0771234567', 'area' => 'Galgamuwa', 'credit_limit' => '50000', 'credit_days' => '30', 'opening_balance' => '12000', 'is_active' => '1',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->pos['owner'])->post(route('purchasing.suppliers.store'), [
        'name' => 'CIC Fertilizers', 'payment_terms_days' => '30', 'opening_balance' => '25000', 'is_active' => '1',
    ])->assertSessionHasNoErrors();

    $supplier = Supplier::firstWhere('name', 'CIC Fertilizers');
    $this->actingAs($this->pos['owner'])->put(route('purchasing.suppliers.update', $supplier), [
        'name' => 'CIC Fertilizers', 'payment_terms_days' => '30', 'opening_balance' => '20000', 'is_active' => '1',
    ])->assertSessionHasNoErrors();

    expect(balanceOf(SystemAccount::AccountsReceivable))->toBe('12000.00')
        ->and(balanceOf(SystemAccount::AccountsPayable))->toBe('20000.00')
        ->and(balanceOf(SystemAccount::OpeningBalanceEquity))->toBe('-8000.00');
});
