<?php

use App\Domain\Customers\Models\Customer;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Identity\Enums\Role;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\System\Import\OpeningBalanceImporter;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->seed([ChartOfAccountsSeeder::class, DocumentSequenceSeeder::class]);
    $this->owner = userWithRole(Role::SuperAdmin);
});

/**
 * @param  list<array<string, string>>  $rows  keyed by template column
 */
function balancesFile(string $type, array $rows): UploadedFile
{
    $headings = OpeningBalanceImporter::headings($type);
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $headings, escape: '');

    foreach ($rows as $row) {
        fputcsv($handle, array_map(fn ($column) => $row[$column] ?? '', $headings), escape: '');
    }

    rewind($handle);
    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
    file_put_contents($path, stream_get_contents($handle));

    return new UploadedFile($path, "{$type}.csv", 'text/csv', null, true);
}

test('the go-live page shows the checklist and the templates download', function () {
    $this->actingAs($this->owner)->get(route('admin.go-live.index'))->assertOk()->assertSee('Readiness checklist')->assertSee('Import credit customers');
    $this->actingAs($this->owner)->get(route('admin.go-live.template', 'customers'))->assertDownload('customers-opening-balances-template.xlsx');
    $this->actingAs($this->owner)->get(route('admin.go-live.template', 'suppliers'))->assertDownload('suppliers-opening-balances-template.xlsx');
});

test('customers are previewed, then imported with their balances posted to the books', function () {
    $response = $this->actingAs($this->owner)->post(route('admin.go-live.preview', 'customers'), [
        'file' => balancesFile('customers', [
            ['name' => 'Sunil Perera', 'phone' => '077 123 4567', 'credit_limit' => '50,000', 'credit_days' => '30', 'opening_balance' => '12500'],
            ['name' => 'Kamala Silva', 'phone' => '0719876543', 'opening_balance' => '3000.50'],
        ]),
    ])->assertOk()->assertSee('Rows ready to import')->assertSee('15,500.50');

    $token = $response->viewData('token');
    $this->actingAs($this->owner)->post(route('admin.go-live.store', ['type' => 'customers', 'token' => $token]))->assertRedirect(route('customers.index'));

    $sunil = Customer::where('phone', '0771234567')->sole();
    expect($sunil->credit_limit)->toBe('50000.00')
        ->and((string) $sunil->balance())->toBe('12500.00')
        ->and((string) app(ChartOfAccounts::class)->get(SystemAccount::AccountsReceivable)->balance())->toBe('15500.50');
    expectBooksBalance();
});

test('rows with problems stop the whole import', function () {
    Customer::factory()->create(['phone' => '0771234567']);

    $this->actingAs($this->owner)->post(route('admin.go-live.preview', 'customers'), [
        'file' => balancesFile('customers', [
            ['name' => 'Sunil Perera', 'phone' => '0771234567', 'opening_balance' => '100'],
            ['name' => '', 'phone' => '0712222222', 'opening_balance' => 'abc'],
            ['name' => 'Twin', 'phone' => '0713333333', 'opening_balance' => '0'],
            ['name' => 'Twin again', 'phone' => '0713333333', 'opening_balance' => '0'],
        ]),
    ])->assertOk()
        ->assertSee('Row 2')->assertSee('The phone has already been taken.')
        ->assertSee('Row 3')->assertSee('Row 5')->assertSee('Same phone number as row 4')
        ->assertDontSee('Import 1 customers');

    expect(Customer::count())->toBe(1);
});

test('suppliers are imported with what the shop owes them', function () {
    $response = $this->actingAs($this->owner)->post(route('admin.go-live.preview', 'suppliers'), [
        'file' => balancesFile('suppliers', [['name' => 'Lanka Fertilizer Co.', 'payment_terms_days' => '45', 'opening_balance' => '250000']]),
    ])->assertOk();

    $this->actingAs($this->owner)->post(route('admin.go-live.store', ['type' => 'suppliers', 'token' => $response->viewData('token')]))->assertRedirect();

    $supplier = Supplier::sole();
    expect($supplier->payment_terms_days)->toBe(45)
        ->and((string) $supplier->balance())->toBe('250000.00');
    expectBooksBalance();
});

test('only the owner opens go-live', function () {
    $this->actingAs(userWithRole(Role::Manager))->get(route('admin.go-live.index'))->assertForbidden();
});
