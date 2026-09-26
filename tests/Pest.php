<?php

use App\Domain\CashDrawer\Actions\OpenDrawerAction;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Catalog\Actions\SaveProductAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Customers\Models\Customer;
use App\Domain\Finance\Actions\SaveBankAccountAction;
use App\Domain\Finance\Enums\BankAccountType;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Finance\Services\FinancialReports;
use App\Domain\HR\Actions\SaveEmployeeAction;
use App\Domain\HR\Models\Attendance;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\Holiday;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Services\TerminalRegistrar;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Sales\Actions\IssueCounterInvoiceAction;
use App\Domain\Sales\Models\Sale;
use App\Models\User;
use Brick\Math\BigDecimal;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against the MySQL test database (citizensDB_testing),
| because row locking and FULLTEXT behave differently on SQLite.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
    })
    ->in('Feature');

/*
| Search tests commit their data (tables are truncated instead of rolled back),
| because InnoDB FULLTEXT indexes only see committed rows. Meilisearch tests use
| the local Meilisearch with a "testing_" index prefix and are skipped when it is down.
*/
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->beforeEach(function () {
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
    })
    ->in('Search');

/*
| Concurrency tests race two MySQL connections against each other, so their data
| must be committed (tables are truncated instead of rolled back).
*/
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->beforeEach(function () {
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
    })
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function userWithRole(Role $role, array $attributes = []): User
{
    // Refresh so columns filled by database defaults exist (models are strict outside production).
    return User::factory()->withRole($role)->create($attributes)->refresh();
}

/**
 * Create a terminal and register a device for it.
 *
 * @return array{0: Terminal, 1: string} the terminal and the plain device token
 */
function registeredTerminal(?Terminal $terminal = null): array
{
    $terminal ??= Terminal::factory()->counter(1)->create();
    $token = app(TerminalRegistrar::class)->register($terminal, User::factory()->create());

    return [$terminal->refresh(), $token];
}

function deviceCookie(): string
{
    return config('pos.device_cookie');
}

/**
 * Create a product the way the product form does. Needs CatalogSeeder (units, price lists, categories).
 *
 * units:  ['bag' => 50]                      extra units and their factor
 * prices: ['Retail' => ['kg' => '190.00']]   price list => unit => price
 */
function createProduct(array $attributes = [], array $units = [], array $prices = [], array $variants = [], string $saleUnit = ''): Product
{
    $unitIds = Unit::pluck('id', 'name');
    $listIds = PriceList::pluck('id', 'name');
    $baseUnit = $attributes['base_unit'] ?? 'piece';
    unset($attributes['base_unit']);

    $data = [
        'short_code' => (string) fake()->unique()->numberBetween(20000, 29999),
        'name' => 'Product '.fake()->unique()->numberBetween(1, 99999),
        'category_id' => Category::firstWhere('name', $attributes['category'] ?? 'Other')->id,
        'base_unit_id' => $unitIds[$baseUnit],
        'is_active' => true,
        'track_batches' => false,
        'track_expiry' => false,
        'reorder_level' => 0,
        'reorder_qty' => 0,
        ...Arr::except($attributes, ['category']),
        'units' => collect([$baseUnit => 1, ...$units])->map(fn ($factor, $unit) => [
            'unit_id' => $unitIds[$unit],
            'factor' => $factor,
            'is_default_sale' => $unit === ($saleUnit ?: $baseUnit),
            'is_default_purchase' => $unit === $baseUnit,
        ])->values()->all(),
        'prices' => collect($prices)->flatMap(fn ($byUnit, $list) => collect($byUnit)->map(fn ($price, $unit) => [
            'unit_id' => $unitIds[$unit],
            'price_list_id' => $listIds[$list],
            'price' => $price,
        ])->values())->all(),
        'variants' => $variants,
    ];

    return app(SaveProductAction::class)->handle($data, User::role(Role::SuperAdmin->value)->first() ?? userWithRole(Role::SuperAdmin));
}

/**
 * Invariant: every batch's cached stock level equals the sum of its movements,
 * and no batch has stock without a movement.
 */
function expectStockMatchesLedger(): void
{
    $ledger = DB::table('stock_movements')->groupBy('batch_id')->selectRaw('batch_id, SUM(qty) AS qty')->pluck('qty', 'batch_id');
    $levels = DB::table('stock_levels')->pluck('qty_on_hand', 'batch_id');

    foreach ($levels as $batchId => $onHand) {
        expect(BigDecimal::of((string) $onHand)->compareTo((string) ($ledger[$batchId] ?? '0')))->toBe(0, "Batch {$batchId}: level {$onHand} ≠ ledger ".($ledger[$batchId] ?? 0));
    }

    expect($ledger->keys()->diff($levels->keys()))->toBeEmpty();
}

/**
 * Urea 50kg: code 1001, sold per bag (50 kg) or per kg.
 */
function createUrea(array $attributes = []): Product
{
    return createProduct(
        [
            'short_code' => '1001',
            'name' => 'Urea 50kg',
            'name_si' => 'යූරියා',
            'aliases' => 'yuriya, urea bag, u50',
            'category' => 'Fertilizers',
            'base_unit' => 'kg',
            'reference_cost' => '160',
            'min_selling_margin_pct' => '5',
            ...$attributes,
        ],
        units: ['bag' => 50],
        prices: ['Retail' => ['kg' => '190.00', 'bag' => '9000.00'], 'Wholesale' => ['bag' => '8800.00']],
        saleUnit: 'bag',
    );
}

/*
|--------------------------------------------------------------------------
| POS helpers (Phase 3)
|--------------------------------------------------------------------------
*/

/**
 * Main cashier + Counter 1 (both registered), owner / manager / staff, catalog and
 * document numbers. Returns the parts tests need.
 *
 * @return array{main: Terminal, mainToken: string, counter: Terminal, counterToken: string, owner: User, manager: User, staff: User}
 */
function posSetup(): array
{
    test()->seed([CatalogSeeder::class, DocumentSequenceSeeder::class]);

    $owner = userWithRole(Role::SuperAdmin, ['name' => 'Owner']);
    $owner->setPin('1111');
    $manager = userWithRole(Role::Manager, ['name' => 'Manager']);
    $manager->setPin('2222');
    $staff = userWithRole(Role::SalesStaff, ['name' => 'Nimal']);
    $staff->setPin('3331');

    [$main, $mainToken] = registeredTerminal(Terminal::factory()->mainCashier()->create());
    Printer::factory()->create(['terminal_id' => $main->id, 'has_cash_drawer' => true]);
    [$counter, $counterToken] = registeredTerminal(Terminal::factory()->counter(1)->create());
    Printer::factory()->create(['terminal_id' => $counter->id]);

    return compact('main', 'mainToken', 'counter', 'counterToken', 'owner', 'manager', 'staff');
}

/**
 * Urea with stock: 20 bags (1000 kg) at Rs. 160 / kg cost. Retail: bag 9000, kg 190.
 */
function ureaInStock(string $kg = '1000'): Product
{
    $urea = createUrea();
    app(StockService::class)->receive($urea, null, $kg, '160');

    return $urea->refresh();
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function cartPayload(array $lines, array $extra = []): array
{
    return [
        'cart_uuid' => $extra['cart_uuid'] ?? (string) Str::uuid(),
        'payment_method' => 'cash',
        'lines' => array_map(fn (array $line) => [
            'key' => $line['key'] ?? $line['product_id'].'-'.($line['variant_id'] ?? 0).'-'.$line['unit_id'],
            'variant_id' => null,
            'discount' => '0',
            ...$line,
        ], $lines),
        ...Arr::except($extra, ['cart_uuid']),
    ];
}

function unitId(string $name): int
{
    return (int) Unit::where('name', $name)->value('id');
}

/**
 * Open the main drawer for a user with a float made of Rs. 1000 notes.
 */
function openDrawer(Terminal $main, User $holder, int $thousands = 5): DrawerSession
{
    return app(OpenDrawerAction::class)->handle($main, $holder, ['1000' => $thousands]);
}

/**
 * The test acting as $user on the device registered with $token (cookies are also sent
 * with JSON requests).
 */
function atTerminal(string $token, User $user): TestCase
{
    return test()->withCredentials()->withCookie(deviceCookie(), $token)->actingAs($user);
}

/*
|--------------------------------------------------------------------------
| Customer helpers (Phase 4)
|--------------------------------------------------------------------------
*/

function creditCustomer(string $limit = '50000', array $attributes = []): Customer
{
    return Customer::factory()->withCredit($limit)->create($attributes)->refresh();
}

/**
 * Print an invoice at Counter 1 through the action. $extra goes into the cart
 * (customer_id, payment_method, tendered, bill_discount …).
 */
function counterInvoice(array $pos, array $lines, array $extra = []): Sale
{
    return app(IssueCounterInvoiceAction::class)->handle($pos['counter'], $pos['staff'], cartPayload($lines, $extra), Str::random(32))['sale']->refresh();
}

/**
 * POST settle on the main terminal as $user (who must hold the open drawer).
 */
function cashierSettle(array $pos, User $user, Sale $sale, array $extra = []): TestResponse
{
    return atTerminal($pos['mainToken'], $user)->postJson(route('api.pos.sales.settle', $sale), ['idempotency_key' => Str::random(32), ...$extra]);
}

/*
|--------------------------------------------------------------------------
| Finance helpers (Phase 5)
|--------------------------------------------------------------------------
*/

/**
 * A bank account created the way the bank account form does (ledger account + opening balance).
 */
function bankAccount(array $attributes = []): BankAccount
{
    return app(SaveBankAccountAction::class)->handle([
        'bank_name' => 'Bank of Ceylon',
        'branch' => 'Kurunegala',
        'account_no' => (string) fake()->unique()->numerify('00########'),
        'account_name' => 'Citizens Agro',
        'type' => BankAccountType::Current,
        'opening_balance' => '0.00',
        'opening_date' => today(),
        'receives_card_payments' => false,
        'is_active' => true,
        ...$attributes,
    ], User::role(Role::SuperAdmin->value)->first() ?? userWithRole(Role::SuperAdmin));
}

/**
 * Post a goods receipt without a purchase order: $bags bags of $product at $costPerBag.
 */
function directGrn(User $user, Supplier $supplier, Product $product, string $bags, string $costPerBag): GoodsReceipt
{
    test()->actingAs($user)->post(route('purchasing.goods-receipts.store'), [
        'supplier_id' => $supplier->id,
        'purchase_order_id' => '',
        'supplier_invoice_no' => 'SINV-'.fake()->unique()->numberBetween(100, 999),
        'received_at' => now()->subMinute()->format('Y-m-d H:i'),
        'lines' => [[
            'po_line_id' => '',
            'product_id' => $product->id,
            'variant_id' => '',
            'unit_id' => unitId('bag'),
            'qty' => $bags,
            'free_qty' => '',
            'unit_cost' => $costPerBag,
        ]],
        'action' => 'post',
    ])->assertSessionHasNoErrors();

    return GoodsReceipt::query()->latest('id')->firstOrFail();
}

/**
 * Invariants of the books: every entry balances, the trial balance balances, and the
 * receivable, payable and bank ledger accounts agree with the customer ledger, the
 * supplier ledger and the bank books.
 */
function expectBooksBalance(): void
{
    $unbalanced = DB::table('journal_lines')->groupBy('journal_entry_id')->havingRaw('SUM(debit) <> SUM(credit)')->pluck('journal_entry_id');
    expect($unbalanced)->toBeEmpty('Unbalanced journal entries: '.$unbalanced->implode(', '));

    expect(app(FinancialReports::class)->trialBalance(today()->addYear())['balanced'])->toBeTrue();

    $balance = fn (SystemAccount $account): string => (string) app(ChartOfAccounts::class)->get($account)->balance();

    $customers = Customer::withTrashed()->get()->reduce(fn (BigDecimal $sum, Customer $customer) => $sum->plus($customer->balance()), BigDecimal::of('0.00'));
    expect($balance(SystemAccount::AccountsReceivable))->toBe((string) $customers, 'Receivable ≠ customer ledger');

    $suppliers = Supplier::withTrashed()->get()->reduce(fn (BigDecimal $sum, Supplier $supplier) => $sum->plus($supplier->balance()), BigDecimal::of('0.00'));
    expect($balance(SystemAccount::AccountsPayable))->toBe((string) $suppliers->toScale(2), 'Payable ≠ supplier ledger');

    foreach (BankAccount::with('account')->get() as $bank) {
        expect((string) $bank->account->balance())->toBe((string) $bank->balance(), "Ledger of {$bank->displayName()} ≠ its bank book");
    }
}

/*
|--------------------------------------------------------------------------
| HR helpers (Phase 6)
|--------------------------------------------------------------------------
*/

/**
 * An employee created the way the employee form does. Needs HrSeeder (default shift, leave types).
 *
 * @param  array<int, array{assigned: bool, value?: string|null}>  $components
 */
function employee(array $attributes = [], ?User $user = null, array $components = []): Employee
{
    return app(SaveEmployeeAction::class)->handle([
        'full_name' => 'Nimal Bandara',
        'join_date' => '2025-01-01',
        'employment_type' => 'permanent',
        'basic_salary' => '50000',
        'is_epf_member' => true,
        'is_active' => true,
        'user_id' => $user?->id,
        'components' => $components,
        ...$attributes,
    ])->refresh();
}

/**
 * Mark every working day of the month (shift days, not holidays) up to today as present
 * 08:00–18:00, except the days given (Y-m-d => status or null for no record).
 *
 * @param  array<string, string|null>  $except
 */
function presentAllMonth(Employee $employee, string $month, array $except = []): void
{
    $start = Carbon::parse($month.'-01');
    $shift = $employee->workingShift();
    $holidays = Holiday::between($start, $start->copy()->endOfMonth());

    for ($day = $start->copy(); $day->lte($start->copy()->endOfMonth()) && $day->lte(today()); $day->addDay()) {
        $key = $day->toDateString();

        if (! $shift->worksOn($day) || isset($holidays[$key])) {
            continue;
        }

        if (array_key_exists($key, $except)) {
            if ($except[$key] !== null) {
                Attendance::create(['employee_id' => $employee->id, 'date' => $key, 'source' => 'manual', 'status' => $except[$key]]);
            }

            continue;
        }

        Attendance::create([
            'employee_id' => $employee->id,
            'date' => $key,
            'clock_in' => $key.' 08:00:00',
            'clock_out' => $key.' 18:00:00',
            'source' => 'pos',
            'status' => 'present',
        ]);
    }
}
