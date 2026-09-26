<?php

use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\CashDrawer\Models\CashMovement;
use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\BankReconciliation;
use App\Domain\Finance\Models\BankTransaction;
use App\Domain\Finance\Services\ChartOfAccounts;

beforeEach(function () {
    $this->pos = posSetup();
    $this->owner = $this->pos['owner'];
});

afterEach(function () {
    expectBooksBalance();
});

function bankForm(array $overrides = []): array
{
    return [
        'bank_name' => 'Bank of Ceylon',
        'branch' => 'Kurunegala',
        'account_no' => '0081234567',
        'account_name' => 'Citizens Agro',
        'type' => 'current',
        'opening_balance' => '250000',
        'opening_date' => today()->startOfMonth()->toDateString(),
        'receives_card_payments' => '1',
        'is_active' => '1',
        ...$overrides,
    ];
}

function moveMoney(array $data)
{
    return test()->actingAs(test()->owner)->post(route('finance.money.store'), ['date' => today()->toDateString(), ...$data]);
}

test('a bank account gets its own ledger account and its opening balance; only one receives card payments', function () {
    $this->actingAs($this->owner)->post(route('finance.bank-accounts.store'), bankForm())->assertRedirect();
    $this->actingAs($this->owner)->post(route('finance.bank-accounts.store'), bankForm(['bank_name' => 'Peoples Bank', 'account_no' => '11', 'opening_balance' => '50000']))->assertRedirect();

    [$boc, $peoples] = BankAccount::with('account')->orderBy('id')->get();

    expect($boc->account->code)->toBe('1501')
        ->and($peoples->account->code)->toBe('1502')
        ->and((string) $boc->balance())->toBe('250000.00')
        ->and($boc->receives_card_payments)->toBeFalse()
        ->and($peoples->receives_card_payments)->toBeTrue()
        ->and((string) app(ChartOfAccounts::class)->get(SystemAccount::OpeningBalanceEquity)->balance())->toBe('300000.00');

    $this->actingAs($this->owner)->put(route('finance.bank-accounts.update', $boc), bankForm(['opening_balance' => '240000', 'receives_card_payments' => '0']))->assertRedirect();

    expect((string) $boc->refresh()->balance())->toBe('240000.00');
});

test('cash from the safe to the bank, bank to bank, and the owner putting money in and taking it out', function () {
    $boc = bankAccount(['opening_balance' => '10000']);
    $peoples = bankAccount(['bank_name' => 'Peoples Bank']);

    moveMoney(['from' => 'owner', 'to' => 'safe', 'amount' => '50000'])->assertSessionHasNoErrors();
    moveMoney(['from' => 'safe', 'to' => "bank:{$boc->id}", 'amount' => '30000', 'reference' => 'SLIP-77'])->assertSessionHasNoErrors();
    moveMoney(['from' => "bank:{$boc->id}", 'to' => "bank:{$peoples->id}", 'amount' => '15000'])->assertSessionHasNoErrors();
    moveMoney(['from' => "bank:{$peoples->id}", 'to' => 'owner', 'amount' => '5000'])->assertSessionHasNoErrors();

    $accounts = app(ChartOfAccounts::class);

    expect((string) $boc->balance())->toBe('25000.00')
        ->and((string) $peoples->balance())->toBe('10000.00')
        ->and((string) $accounts->get(SystemAccount::Safe)->balance())->toBe('20000.00')
        ->and((string) $accounts->get(SystemAccount::OwnerCapital)->balance())->toBe('50000.00')
        ->and((string) $accounts->get(SystemAccount::OwnerDrawings)->balance())->toBe('-5000.00')
        ->and(BankTransaction::where('type', BankTransactionType::TransferOut)->count())->toBe(1)
        ->and(BankTransaction::where('reference', 'SLIP-77')->value('type'))->toBe(BankTransactionType::Deposit);
});

test('cash from the open drawer to the bank is a bank deposit on the drawer session', function () {
    $session = openDrawer($this->pos['main'], $this->owner, 10);
    $boc = bankAccount();

    moveMoney(['from' => 'drawer', 'to' => "bank:{$boc->id}", 'amount' => '6000'])->assertSessionHasNoErrors();

    expect(CashMovement::sole()->type)->toBe(CashMovementType::BankDeposit)
        ->and((string) app(DrawerCalculator::class)->expectedCash($session))->toBe('4000.00')
        ->and((string) $boc->balance())->toBe('6000.00');

    moveMoney(['from' => 'drawer', 'to' => "bank:{$boc->id}", 'amount' => '5000'])->assertSessionHasErrors('amount');
    moveMoney(['from' => 'drawer', 'to' => 'safe', 'amount' => '100'])->assertSessionHasErrors('to');
});

test('card and transfer payments wait until the owner moves them into the bank', function () {
    openDrawer($this->pos['main'], $this->owner);
    $boc = bankAccount();
    $urea = ureaInStock();
    $sale = counterInvoice($this->pos, [['product_id' => $urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['payment_method' => 'card']);
    cashierSettle($this->pos, $this->owner, $sale, ['method' => 'card', 'reference' => 'SLIP-5'])->assertOk();

    expect((string) $boc->balance())->toBe('0.00')
        ->and((string) app(ChartOfAccounts::class)->get(SystemAccount::CardClearing)->balance())->toBe('9000.00');

    moveMoney(['from' => 'clearing', 'to' => 'safe', 'amount' => '9000'])->assertSessionHasErrors('to');
    moveMoney(['from' => 'clearing', 'to' => "bank:{$boc->id}", 'amount' => '9000', 'reference' => 'Card batch'])->assertSessionHasNoErrors();

    expect((string) $boc->balance())->toBe('9000.00')
        ->and((string) app(ChartOfAccounts::class)->get(SystemAccount::CardClearing)->balance())->toBe('0.00');
});

test('bank charges and interest from the statement', function () {
    $boc = bankAccount(['opening_balance' => '1000']);

    $this->actingAs($this->owner)->post(route('finance.bank-accounts.charge', $boc), ['type' => 'charge', 'amount' => '150', 'date' => today()->toDateString(), 'description' => 'SMS fee'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('finance.bank-accounts.charge', $boc), ['type' => 'interest', 'amount' => '42.50', 'date' => today()->toDateString()])->assertSessionHasNoErrors();

    expect((string) $boc->balance())->toBe('892.50')
        ->and((string) app(ChartOfAccounts::class)->get(SystemAccount::BankCharges)->balance())->toBe('150.00')
        ->and((string) app(ChartOfAccounts::class)->get(SystemAccount::InterestIncome)->balance())->toBe('42.50');
});

test('reconciliation ticks statement lines and keeps the difference', function () {
    $boc = bankAccount(['opening_balance' => '1000']);
    moveMoney(['from' => 'owner', 'to' => "bank:{$boc->id}", 'amount' => '500']);
    moveMoney(['from' => 'owner', 'to' => "bank:{$boc->id}", 'amount' => '300']);
    [$first, $second] = BankTransaction::orderBy('id')->get();

    $this->actingAs($this->owner)->post(route('finance.bank-accounts.reconcile.store', $boc), [
        'statement_date' => today()->toDateString(),
        'statement_balance' => '1500',
        'transactions' => [$first->id],
    ])->assertSessionHasNoErrors();

    $reconciliation = BankReconciliation::sole();
    expect($reconciliation->cleared_balance)->toBe('1500.00')
        ->and($reconciliation->difference)->toBe('0.00')
        ->and($first->refresh()->reconciled_at)->not->toBeNull()
        ->and($second->refresh()->reconciled_at)->toBeNull();

    // A line cannot be ticked twice.
    $this->actingAs($this->owner)->post(route('finance.bank-accounts.reconcile.store', $boc), [
        'statement_date' => today()->toDateString(),
        'statement_balance' => '1800',
        'transactions' => [$first->id, $second->id],
    ])->assertSessionHasErrors('transactions');
});

test('the banking pages show balances and the bank book', function () {
    $boc = bankAccount(['opening_balance' => '1000']);
    moveMoney(['from' => 'owner', 'to' => "bank:{$boc->id}", 'amount' => '500', 'reference' => 'DEP-1']);

    $this->actingAs($this->owner)->get(route('finance.bank-accounts.index'))->assertOk()->assertSee('1,500.00');
    $this->actingAs($this->owner)->get(route('finance.bank-accounts.show', $boc))->assertOk()->assertSee('DEP-1')->assertSee('1,500.00');
    $this->actingAs($this->owner)->get(route('finance.bank-accounts.reconcile', $boc))->assertOk()->assertSee('DEP-1');
    $this->actingAs($this->owner)->get(route('finance.money.create'))->assertOk();
    $this->actingAs($this->owner)->get(route('finance.bank-accounts.create'))->assertOk();
    $this->actingAs($this->owner)->get(route('finance.bank-accounts.edit', $boc))->assertOk();
});
