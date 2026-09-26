<?php

use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Identity\Actions\CreateDelegationAction;
use App\Domain\Identity\Exceptions\NonDelegablePermissionException;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->pos = posSetup();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->urea = ureaInStock();
    openDrawer($this->pos['main'], $this->pos['owner']);

    $sale = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '2']], ['tendered' => '20000']);
    cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk();
});

test('the owner sees every finance page', function (string $route, array $params = []) {
    $this->actingAs($this->pos['owner'])->get(route($route, $params))->assertOk();
})->with([
    ['finance.bank-accounts.index'],
    ['finance.cheques.index'],
    ['finance.cheques.calendar'],
    ['finance.expenses.index'],
    ['finance.expenses.create'],
    ['finance.expense-categories.index'],
    ['finance.journal.index'],
    ['finance.accounts.index'],
    ['finance.accounts.create'],
    ['finance.reports.trial-balance'],
    ['finance.reports.profit-loss'],
    ['finance.reports.balance-sheet'],
    ['finance.reports.cash-book'],
    ['finance.reports.cash-book', ['account' => 'drawer']],
    ['purchasing.supplier-payments.index'],
    ['purchasing.supplier-payments.create'],
]);

test('the journal, an entry and an account ledger show the sale', function () {
    $entry = JournalEntry::where('event', 'sale.settled')->sole();
    $sales = app(ChartOfAccounts::class)->get(SystemAccount::SalesRevenue);

    $this->actingAs($this->pos['owner'])->get(route('finance.journal.index'))->assertOk()->assertSee($entry->number);
    $this->actingAs($this->pos['owner'])->get(route('finance.journal.show', $entry))->assertOk()->assertSee('18,000.00');
    $this->actingAs($this->pos['owner'])->get(route('finance.accounts.show', $sales))->assertOk()->assertSee($entry->number);
});

test('profit and loss: sales less cost of goods sold', function () {
    $this->actingAs($this->pos['owner'])->get(route('finance.reports.profit-loss'))
        ->assertOk()
        ->assertSeeInOrder(['Sales', '18,000.00', 'Gross profit', '2,000.00']);

    $this->actingAs($this->pos['owner'])->get(route('finance.reports.balance-sheet'))->assertOk()->assertDontSee('does not balance');
    $this->actingAs($this->pos['owner'])->get(route('finance.reports.trial-balance'))->assertOk()->assertDontSee('Debits and credits differ');
});

test('the owner adds an account; codes must fit the type; system accounts keep their code', function () {
    $this->actingAs($this->pos['owner'])->post(route('finance.accounts.store'), ['code' => '2100', 'name' => 'Bank loan', 'type' => 'liability'])->assertRedirect();
    $this->actingAs($this->pos['owner'])->post(route('finance.accounts.store'), ['code' => '1600', 'name' => 'Wrong', 'type' => 'expense'])->assertSessionHasErrors('code');

    $safe = app(ChartOfAccounts::class)->get(SystemAccount::Safe);
    $this->actingAs($this->pos['owner'])->put(route('finance.accounts.update', $safe), ['code' => '9999', 'name' => 'Safe (office)', 'type' => 'expense'])->assertRedirect();

    expect($safe->refresh()->name)->toBe('Safe (office)')
        ->and($safe->code)->toBe('1020')
        ->and($safe->type->value)->toBe('asset');
});

test('the Manager sees expenses but not banks, cheques, the journal or the financial reports', function () {
    $manager = $this->actingAs($this->pos['manager']);

    $manager->get(route('finance.expenses.index'))->assertOk();
    foreach (['finance.bank-accounts.index', 'finance.money.create', 'finance.cheques.index', 'finance.journal.index', 'finance.accounts.index', 'finance.reports.profit-loss', 'finance.expense-categories.index'] as $route) {
        $manager->get(route($route))->assertForbidden();
    }

    $this->actingAs($this->pos['staff'])->get(route('finance.expenses.index'))->assertForbidden();
});

test('finance can never be handed over to the Manager', function () {
    app(CreateDelegationAction::class)->handle($this->pos['owner'], $this->pos['manager'], now()->addHours(3), ['pos.settle', 'finance.banks.manage']);
})->throws(NonDelegablePermissionException::class);

test('a delegated Manager still cannot open banking or the journal', function () {
    app(CreateDelegationAction::class)->handle($this->pos['owner'], $this->pos['manager'], now()->addHours(3), ['pos.settle', 'drawer.manage', 'customers.credit.manage']);

    $this->actingAs($this->pos['manager'])->get(route('finance.bank-accounts.index'))->assertForbidden();
    $this->actingAs($this->pos['manager'])->get(route('finance.journal.index'))->assertForbidden();
});
