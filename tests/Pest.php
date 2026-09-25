<?php

use App\Domain\CashDrawer\Actions\OpenDrawerAction;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Catalog\Actions\SaveProductAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Services\TerminalRegistrar;
use App\Domain\Inventory\Services\StockService;
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
