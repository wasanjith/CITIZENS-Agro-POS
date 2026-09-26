<?php

use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\CashDrawer\Models\CashMovement;
use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Services\ChartOfAccounts;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->pos = posSetup();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->electricity = ExpenseCategory::firstWhere('name', 'Electricity');
    $this->transport = ExpenseCategory::firstWhere('name', 'Transport & fuel');
});

afterEach(function () {
    expectBooksBalance();
});

function expenseForm(array $overrides = []): array
{
    return [
        'date' => today()->toDateString(),
        'expense_category_id' => test()->transport->id,
        'amount' => '1500',
        'paid_from' => 'cash_drawer',
        'payee' => 'Lorry driver',
        ...$overrides,
    ];
}

test('the Manager pays petty cash from the drawer with a photo of the bill', function () {
    Storage::fake('local');
    $session = openDrawer($this->pos['main'], $this->pos['owner']);

    $this->actingAs($this->pos['manager'])
        ->post(route('finance.expenses.store'), expenseForm(['receipt' => UploadedFile::fake()->image('bill.jpg')]))
        ->assertRedirect();

    $expense = Expense::sole();
    expect($expense->number)->toStartWith('EXP-')
        ->and($expense->drawer_session_id)->toBe($session->id)
        ->and(CashMovement::sole()->type)->toBe(CashMovementType::PayOut)
        ->and((string) app(DrawerCalculator::class)->expectedCash($session))->toBe('3500.00')
        ->and((string) $this->transport->account->balance())->toBe('1500.00')
        ->and(JournalEntry::where('event', 'cash.moved')->exists())->toBeFalse();

    Storage::disk('local')->assertExists($expense->receipt_path);
    $this->actingAs($this->pos['manager'])->get(route('finance.expenses.receipt', $expense))->assertOk();
    $this->actingAs($this->pos['manager'])->get(route('finance.expenses.show', $expense))->assertOk()->assertSee('Lorry driver');
});

test('petty cash needs an open drawer with enough cash', function () {
    $this->actingAs($this->pos['manager'])->post(route('finance.expenses.store'), expenseForm())->assertSessionHasErrors('paid_from');

    openDrawer($this->pos['main'], $this->pos['owner'], 1);
    $this->actingAs($this->pos['manager'])->post(route('finance.expenses.store'), expenseForm(['amount' => '1500']))->assertSessionHasErrors('paid_from');

    expect(Expense::count())->toBe(0);
});

test('the Manager can only pay from the drawer; the owner pays from the safe or a bank', function () {
    $bank = bankAccount(['opening_balance' => '20000']);

    $this->actingAs($this->pos['manager'])->post(route('finance.expenses.store'), expenseForm(['paid_from' => 'safe']))->assertForbidden();

    $this->actingAs($this->pos['owner'])->post(route('finance.expenses.store'), expenseForm(['paid_from' => 'safe', 'expense_category_id' => $this->electricity->id, 'amount' => '4200']))->assertRedirect();
    $this->actingAs($this->pos['owner'])->post(route('finance.expenses.store'), expenseForm(['paid_from' => 'bank', 'bank_account_id' => $bank->id, 'amount' => '8000']))->assertRedirect();

    expect((string) $bank->balance())->toBe('12000.00')
        ->and((string) app(ChartOfAccounts::class)->get(SystemAccount::Safe)->balance())->toBe('-4200.00')
        ->and((string) $this->electricity->account->balance())->toBe('4200.00');
});

test('cancelling an expense reverses it and puts the cash back while the drawer is open', function () {
    $session = openDrawer($this->pos['main'], $this->pos['owner']);
    $this->actingAs($this->pos['manager'])->post(route('finance.expenses.store'), expenseForm());
    $expense = Expense::sole();

    $this->actingAs($this->pos['manager'])->post(route('finance.expenses.cancel', $expense), ['reason' => 'Entered twice'])->assertForbidden();
    $this->actingAs($this->pos['owner'])->post(route('finance.expenses.cancel', $expense), ['reason' => 'Entered twice'])->assertSessionHasNoErrors();

    expect($expense->refresh()->isCancelled())->toBeTrue()
        ->and((string) app(DrawerCalculator::class)->expectedCash($session))->toBe('5000.00')
        ->and((string) $this->transport->account->balance())->toBe('0.00')
        ->and(JournalEntry::where('event', 'expense.cancelled')->sole()->reverses_id)->not->toBeNull();

    $this->actingAs($this->pos['owner'])->post(route('finance.expenses.cancel', $expense), ['reason' => 'Again'])->assertSessionHasErrors('reason');
});

test('a new expense head gets its own expense account', function () {
    $this->actingAs($this->pos['owner'])->post(route('finance.expense-categories.store'), ['name' => 'Security'])->assertSessionHasNoErrors();

    $category = ExpenseCategory::firstWhere('name', 'Security');
    expect($category->account->code)->toStartWith('60')
        ->and($category->account->type->value)->toBe('expense');

    $this->actingAs($this->pos['manager'])->post(route('finance.expense-categories.store'), ['name' => 'Other'])->assertForbidden();
});

test('the expense list totals what was not cancelled', function () {
    openDrawer($this->pos['main'], $this->pos['owner']);
    $this->actingAs($this->pos['owner'])->post(route('finance.expenses.store'), expenseForm(['amount' => '1000']));
    $this->actingAs($this->pos['owner'])->post(route('finance.expenses.store'), expenseForm(['amount' => '250']));
    $this->actingAs($this->pos['owner'])->post(route('finance.expenses.cancel', Expense::first()), ['reason' => 'Mistake']);

    $this->actingAs($this->pos['manager'])->get(route('finance.expenses.index'))->assertOk()->assertSee('250.00');
    $this->actingAs($this->pos['manager'])->get(route('finance.expenses.create'))->assertOk()->assertDontSee('Cash from the safe');
});
