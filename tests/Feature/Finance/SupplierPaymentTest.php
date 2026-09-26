<?php

use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Purchasing\Enums\SupplierLedgerType;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierPayment;

beforeEach(function () {
    $this->pos = posSetup();
    $this->urea = createUrea();
    $this->supplier = Supplier::factory()->create();
    $this->bank = bankAccount(['opening_balance' => '100000']);

    // Two deliveries: 17 000 and 25 500.
    $this->first = directGrn($this->pos['owner'], $this->supplier, $this->urea, '2', '8500');
    $this->second = directGrn($this->pos['owner'], $this->supplier, $this->urea, '3', '8500');
});

afterEach(function () {
    expectBooksBalance();
});

function paySupplier(array $data)
{
    return test()->actingAs(test()->pos['owner'])->post(route('purchasing.supplier-payments.store', test()->supplier), [
        'date' => today()->toDateString(),
        'paid_from' => 'bank',
        'bank_account_id' => test()->bank->id,
        'allocation_mode' => 'fifo',
        ...$data,
    ]);
}

test('a bank payment is applied to the oldest goods receipt first and leaves the bank', function () {
    paySupplier(['amount' => '20000', 'reference' => 'TT-1'])->assertRedirect();

    $payment = SupplierPayment::sole();
    expect($payment->number)->toStartWith('SP-')
        ->and($this->first->refresh()->amount_paid)->toBe('17000.00')
        ->and($this->second->refresh()->amount_paid)->toBe('3000.00')
        ->and((string) $this->supplier->balance())->toBe('22500.00')
        ->and($this->supplier->ledger()->where('type', SupplierLedgerType::Payment)->value('debit'))->toBe('20000.00')
        ->and((string) $this->bank->balance())->toBe('80000.00');
});

test('the owner can choose the goods receipts, and cannot pay more on one than is unpaid', function () {
    paySupplier(['amount' => '10000', 'allocation_mode' => 'manual', 'allocations' => [$this->second->id => '30000']])->assertSessionHasErrors("allocations.{$this->second->id}");
    paySupplier(['amount' => '10000', 'allocation_mode' => 'manual', 'allocations' => [$this->second->id => '10000']])->assertRedirect();

    expect($this->first->refresh()->amount_paid)->toBe('0.00')
        ->and($this->second->refresh()->amount_paid)->toBe('10000.00');
});

test('cash from the safe or the drawer', function () {
    $session = openDrawer($this->pos['main'], $this->pos['owner'], 20);

    paySupplier(['amount' => '5000', 'paid_from' => 'safe'])->assertRedirect();
    paySupplier(['amount' => '7000', 'paid_from' => 'cash_drawer'])->assertRedirect();

    expect((string) app(DrawerCalculator::class)->expectedCash($session))->toBe('13000.00')
        ->and((string) app(ChartOfAccounts::class)->get(SystemAccount::Safe)->balance())->toBe('-25000.00')
        ->and((string) $this->supplier->balance())->toBe('30500.00');
});

test('only the owner pays suppliers; the Manager can see the payments', function () {
    paySupplier(['amount' => '1000'])->assertRedirect();

    $this->actingAs($this->pos['manager'])->post(route('purchasing.supplier-payments.store', $this->supplier), ['amount' => '1000', 'date' => today()->toDateString(), 'paid_from' => 'safe', 'allocation_mode' => 'fifo'])->assertForbidden();
    $this->actingAs($this->pos['manager'])->get(route('purchasing.supplier-payments.index'))->assertOk()->assertSee(SupplierPayment::sole()->number);
    $this->actingAs($this->pos['manager'])->get(route('purchasing.supplier-payments.show', SupplierPayment::sole()))->assertOk()->assertDontSee('JE-');
    $this->actingAs($this->pos['staff'])->get(route('purchasing.supplier-payments.index'))->assertForbidden();

    $this->actingAs($this->pos['owner'])->get(route('purchasing.supplier-payments.create', ['supplier' => $this->supplier->id]))->assertOk()->assertSee($this->second->number);
    $this->actingAs($this->pos['owner'])->get(route('purchasing.supplier-payments.show', SupplierPayment::sole()))->assertOk()->assertSee('JE-');
});
