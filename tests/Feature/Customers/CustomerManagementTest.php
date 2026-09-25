<?php

use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Jobs\OverdueCreditReminderJob;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerLedgerEntry;
use App\Domain\Customers\Notifications\OverdueCreditAlert;
use App\Domain\Customers\Services\CustomerStatement;
use App\Domain\Identity\Enums\Role;
use App\Domain\Sales\Support\InvoicePresenter;
use App\Domain\System\Services\Settings;
use Database\Seeders\DevelopmentCustomerSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->pos = posSetup();
});

function customerForm(array $overrides = []): array
{
    return [
        'name' => 'Sunil Perera',
        'name_si' => 'සුනිල් පෙරේරා',
        'phone' => '077 123 4567',
        'nic' => '881234567V',
        'area' => 'Thambuttegama',
        'credit_limit' => '50000',
        'credit_days' => '30',
        'opening_balance' => '12500',
        'is_active' => '1',
        ...$overrides,
    ];
}

test('the Manager adds a customer: numbered, phone cleaned, opening balance on the account', function () {
    $this->actingAs($this->pos['manager'])->post(route('customers.store'), customerForm())->assertRedirect();

    $customer = Customer::sole();
    expect($customer->code)->toBe('C-00001')
        ->and($customer->phone)->toBe('0771234567')
        ->and($customer->created_by)->toBe($this->pos['manager']->id)
        ->and((string) $customer->balance())->toBe('12500.00')
        ->and(CustomerLedgerEntry::sole()->type)->toBe(CustomerLedgerType::Opening);
});

test('phone numbers are unique and the NIC must look like an NIC', function () {
    $this->actingAs($this->pos['manager'])->post(route('customers.store'), customerForm())->assertRedirect();

    $this->actingAs($this->pos['manager'])->post(route('customers.store'), customerForm(['phone' => '0771234567']))->assertSessionHasErrors('phone');
    $this->actingAs($this->pos['manager'])->post(route('customers.store'), customerForm(['phone' => '0712222222', 'nic' => '12345']))->assertSessionHasErrors('nic');
});

test('editing a customer does not touch the account', function () {
    $this->actingAs($this->pos['manager'])->post(route('customers.store'), customerForm())->assertRedirect();
    $customer = Customer::sole();

    $this->actingAs($this->pos['manager'])->put(route('customers.update', $customer), customerForm(['credit_limit' => '80000', 'opening_balance' => '99999']))->assertRedirect();

    expect($customer->refresh()->credit_limit)->toBe('80000.00')
        ->and(CustomerLedgerEntry::count())->toBe(1);
});

test('Sales Staff can look up customers but not change them', function () {
    $customer = creditCustomer();

    $this->actingAs($this->pos['staff'])->get(route('customers.index'))->assertOk()->assertSee($customer->name);
    $this->actingAs($this->pos['staff'])->get(route('customers.show', $customer))->assertOk();
    $this->actingAs($this->pos['staff'])->get(route('customers.create'))->assertForbidden();
    $this->actingAs($this->pos['staff'])->post(route('customers.store'), customerForm())->assertForbidden();
    $this->actingAs($this->pos['staff'])->put(route('customers.update', $customer), customerForm())->assertForbidden();
});

test('a customer with account entries cannot be deleted', function () {
    $this->actingAs($this->pos['manager'])->post(route('customers.store'), customerForm())->assertRedirect();
    $customer = Customer::sole();

    $this->actingAs($this->pos['manager'])->delete(route('customers.destroy', $customer))->assertRedirect()->assertSessionHas('error');
    expect($customer->refresh()->trashed())->toBeFalse();

    $empty = creditCustomer();
    $this->actingAs($this->pos['manager'])->delete(route('customers.destroy', $empty))->assertRedirect(route('customers.index'));
    expect($empty->refresh()->trashed())->toBeTrue();
});

test('the counter finds customers by phone, name, NIC or village', function () {
    $sunil = creditCustomer('50000', ['name' => 'Sunil Perera', 'phone' => '0771234567', 'nic' => '881234567V', 'area' => 'Eppawala']);
    creditCustomer('50000', ['name' => 'Kamal Silva', 'phone' => '0712222222', 'area' => 'Talawa']);
    Customer::factory()->inactive()->create(['name' => 'Sunil Old']);

    foreach (['1234567', 'sunil', '881234567', 'eppa'] as $term) {
        atTerminal($this->pos['counterToken'], $this->pos['staff'])
            ->getJson(route('api.pos.customers.index', ['q' => $term]))
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.id', $sunil->id);
    }
});

test('counter staff can quick-add a customer, with no credit', function () {
    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->postJson(route('api.pos.customers.store'), ['name' => 'Nimal', 'phone' => '+94 77 555 1212', 'area' => 'Galnewa'])
        ->assertCreated()
        ->assertJsonPath('customer.phone', '0775551212')
        ->assertJsonPath('customer.credit_limit', '0.00');

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->postJson(route('api.pos.customers.store'), ['name' => 'Nimal again', 'phone' => '0775551212'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');
});

test('customer pages render: list, profile, form, ageing', function () {
    $customer = creditCustomer('50000', ['name' => 'Sunil Perera']);
    $sale = counterInvoice($this->pos, [['product_id' => ureaInStock()->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['customer_id' => $customer->id, 'payment_method' => 'credit']);
    openDrawer($this->pos['main'], $this->pos['owner']);
    cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk();
    $sale->refresh()->forceFill(['due_date' => today()->subDays(40)])->save();

    $this->actingAs($this->pos['owner'])->get(route('customers.index', ['filter' => ['overdue' => 1]]))->assertOk()->assertSee('Sunil Perera');
    $this->actingAs($this->pos['owner'])->get(route('customers.show', $customer))->assertOk()->assertSee($sale->invoice_no)->assertSee('9,000.00');
    $this->actingAs($this->pos['owner'])->get(route('customers.edit', $customer))->assertOk();
    $this->actingAs($this->pos['owner'])->get(route('customers.create'))->assertOk();
    $this->actingAs($this->pos['owner'])->get(route('customers.ageing'))->assertOk()->assertSee('Sunil Perera');
    $this->actingAs($this->pos['owner'])->get(route('api.customers', ['q' => 'sunil']))->assertOk()->assertJsonPath('0.id', $customer->id);

    $ageing = app(CustomerStatement::class)->ageingAll()->sole();
    expect($ageing['buckets']['31_60'])->toBe('9000.00')->and($ageing['total'])->toBe('9000.00');
});

test('the statement has the opening balance, a running balance and the unpaid invoices', function () {
    $this->actingAs($this->pos['manager'])->post(route('customers.store'), customerForm(['opening_balance' => '1000']))->assertRedirect();
    $customer = Customer::sole();
    $sale = counterInvoice($this->pos, [['product_id' => ureaInStock()->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['customer_id' => $customer->id, 'payment_method' => 'credit']);
    openDrawer($this->pos['main'], $this->pos['owner']);
    cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk();

    $statement = app(CustomerStatement::class)->build($customer, today()->startOfDay(), today()->endOfDay());
    expect($statement['opening'])->toBe('0.00')
        ->and(array_column($statement['rows'], 'balance'))->toBe(['1000.00', '10000.00'])
        ->and($statement['closing'])->toBe('10000.00')
        ->and($statement['open_invoices']->pluck('id')->all())->toBe([$sale->id]);

    $html = view('print.customer-statement', [
        'statement' => $statement,
        'language' => 'si',
        'shop' => app(Settings::class)->group('shop'),
    ])->render();

    expect($html)->toContain('ගිණුම් ප්‍රකාශය')->toContain('සුනිල් පෙරේරා')->toContain($sale->invoice_no)->toContain('10,000.00');
    expect(app(InvoicePresenter::class)->language(null, 'en'))->toBe('en');
});

test('every morning the Owner and Manager hear about overdue credit, unless switched off', function () {
    Notification::fake();

    $customer = creditCustomer('50000');
    $sale = counterInvoice($this->pos, [['product_id' => ureaInStock()->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['customer_id' => $customer->id, 'payment_method' => 'credit']);
    openDrawer($this->pos['main'], $this->pos['owner']);
    cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk();

    // Not due yet: nothing is sent.
    app(OverdueCreditReminderJob::class)->handle(app(Settings::class));
    Notification::assertNothingSent();

    $sale->refresh()->forceFill(['due_date' => today()->subDays(7)])->save();
    app(OverdueCreditReminderJob::class)->handle(app(Settings::class));
    Notification::assertSentTo($this->pos['owner'], OverdueCreditAlert::class, fn (OverdueCreditAlert $alert) => $alert->customerCount === 1 && $alert->amount === '9000.00');
    Notification::assertSentTo($this->pos['manager'], OverdueCreditAlert::class);
    Notification::assertNotSentTo($this->pos['staff'], OverdueCreditAlert::class);

    Notification::fake();
    app(Settings::class)->setGroup('customers', ['overdue_alert' => false]);
    app(OverdueCreditReminderJob::class)->handle(app(Settings::class));
    Notification::assertNothingSent();
});

test('taking customer payments is part of a cashier handover, never a Sales Staff permission', function () {
    expect(config('pos.permissions')['customers.credit.manage']['delegable'])->toBeTrue()
        ->and(userWithRole(Role::SalesStaff)->can('customers.credit.manage'))->toBeFalse();
});

test('the dummy customer seeder can run twice and gives opening balances', function () {
    $this->seed(DevelopmentCustomerSeeder::class);
    $this->seed(DevelopmentCustomerSeeder::class);

    expect(Customer::count())->toBe(8)
        ->and(Customer::where('is_active', false)->count())->toBe(1)
        ->and((string) Customer::firstWhere('phone', '0771234567')->balance())->toBe('45000.00')
        ->and(CustomerLedgerEntry::where('type', CustomerLedgerType::Opening)->count())->toBe(5);
});
