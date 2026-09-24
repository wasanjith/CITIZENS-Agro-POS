<?php

use App\Domain\Catalog\Actions\SaveProductAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Services\TerminalRegistrar;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
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
