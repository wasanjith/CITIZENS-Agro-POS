<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Enums\QuotationStatus;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Quotation;
use App\Domain\Sales\Models\Sale;
use App\Domain\System\Services\Settings;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pos = posSetup();
    $this->urea = ureaInStock();
    $this->lines = [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '3', 'discount' => '500']];
});

function quoteAtCounter(array $pos, array $lines, array $extra = [])
{
    return atTerminal($pos['counterToken'], $pos['staff'])
        ->postJson(route('api.pos.quotations.store'), [...cartPayload($lines, $extra), 'idempotency_key' => $extra['idempotency_key'] ?? Str::random(32)]);
}

test('a quotation is priced on the server, numbered, printed and reserves no stock', function () {
    app(Settings::class)->setGroup('customers', ['quotation_valid_days' => 10]);

    quoteAtCounter($this->pos, $this->lines, ['customer_name' => 'Ranjith', 'lines' => [[...$this->lines[0], 'key' => 'k', 'variant_id' => null, 'unit_price' => '1']]])
        ->assertCreated()
        ->assertJsonPath('quotation.customer', 'Ranjith')
        ->assertJsonPath('quotation.total', '26500.00');

    $quotation = Quotation::sole();
    expect($quotation->number)->toStartWith('QT-')
        ->and($quotation->status)->toBe(QuotationStatus::Open)
        ->and($quotation->valid_until->toDateString())->toBe(today()->addDays(10)->toDateString())
        ->and($quotation->lines->sole()->line_total)->toBe('26500.00')
        ->and(StockLevel::sum('qty_reserved'))->toEqual(0)
        ->and(PrintJob::where('document_type', PrintDocumentType::Quotation)->where('document_id', $quotation->id)->exists())->toBeTrue();
});

test('the same quotation request twice makes one quotation', function () {
    $key = Str::random(32);

    quoteAtCounter($this->pos, $this->lines, ['idempotency_key' => $key])->assertCreated();
    quoteAtCounter($this->pos, $this->lines, ['idempotency_key' => $key])->assertOk();

    expect(Quotation::count())->toBe(1);
});

test('a discount over the staff limit needs approval before a quotation prints', function () {
    quoteAtCounter($this->pos, [[...$this->lines[0], 'discount' => '5000']])->assertUnprocessable()->assertJsonValidationErrors('discount');
});

test('loading a quotation gives the cart back; printing the invoice marks it billed', function () {
    $customer = creditCustomer();
    quoteAtCounter($this->pos, $this->lines, ['customer_id' => $customer->id])->assertCreated();
    $quotation = Quotation::sole();

    $cart = atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->getJson(route('api.pos.quotations.cart', $quotation))
        ->assertOk()
        ->assertJsonPath('cart.quotation_id', $quotation->id)
        ->assertJsonPath('cart.customer_id', $customer->id)
        ->json('cart');

    $sale = counterInvoice($this->pos, $cart['lines'], ['quotation_id' => $quotation->id, 'customer_id' => $customer->id, 'tendered' => '30000']);

    expect($sale->quotation_id)->toBe($quotation->id)
        ->and($sale->total)->toBe('26500.00')
        ->and($quotation->refresh()->status)->toBe(QuotationStatus::Converted)
        ->and($quotation->converted_sale_id)->toBe($sale->id);

    // A billed quotation cannot be loaded again, and a second invoice does not link to it.
    atTerminal($this->pos['counterToken'], $this->pos['staff'])->getJson(route('api.pos.quotations.cart', $quotation))->assertUnprocessable();
    $again = counterInvoice($this->pos, $cart['lines'], ['quotation_id' => $quotation->id, 'tendered' => '30000']);
    expect($again->quotation_id)->toBeNull();
});

test('an expired quotation cannot be loaded and does not show in the open list', function () {
    quoteAtCounter($this->pos, $this->lines)->assertCreated();
    $quotation = Quotation::sole();
    $quotation->forceFill(['valid_until' => today()->subDay()])->save();

    atTerminal($this->pos['counterToken'], $this->pos['staff'])->getJson(route('api.pos.quotations.cart', $quotation))->assertUnprocessable();
    atTerminal($this->pos['counterToken'], $this->pos['staff'])->getJson(route('api.pos.quotations.index'))->assertOk()->assertJsonCount(0, 'quotations');
    expect($quotation->effectiveStatus())->toBe(QuotationStatus::Expired);
});

test('open quotations can be found by number or customer name', function () {
    quoteAtCounter($this->pos, $this->lines, ['customer_name' => 'Ranjith'])->assertCreated();
    quoteAtCounter($this->pos, $this->lines, ['customer_name' => 'Somapala'])->assertCreated();
    $second = Quotation::latest('id')->first();

    atTerminal($this->pos['counterToken'], $this->pos['staff'])->getJson(route('api.pos.quotations.index', ['q' => 'Ranj']))->assertJsonCount(1, 'quotations')->assertJsonPath('quotations.0.customer', 'Ranjith');
    atTerminal($this->pos['counterToken'], $this->pos['staff'])->getJson(route('api.pos.quotations.index', ['q' => $second->number]))->assertJsonPath('quotations.0.id', $second->id);
});

test('the 80 mm quotation prints in Sinhala; back-office pages render; the writer can cancel it', function () {
    quoteAtCounter($this->pos, $this->lines, ['customer_name' => 'Ranjith'])->assertCreated();
    $quotation = Quotation::sole();
    $job = PrintJob::where('document_type', PrintDocumentType::Quotation)->sole();

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->get(route('pos.quotations.print', ['quotation' => $quotation, 'job' => $job->id]))
        ->assertOk()
        ->assertSee('මිල ගණන් පත්‍රය')
        ->assertSee('යූරියා')
        ->assertSee('26,500.00')
        ->assertSee('window.print()', false);

    $this->actingAs($this->pos['owner'])->get(route('quotations.index'))->assertOk()->assertSee($quotation->number);
    $this->actingAs($this->pos['staff'])->get(route('quotations.show', $quotation))->assertOk()->assertSee('Ranjith');

    $this->actingAs($this->pos['staff'])->post(route('quotations.cancel', $quotation))->assertRedirect();
    expect($quotation->refresh()->status)->toBe(QuotationStatus::Cancelled);
});

test('another staff member cannot cancel someone else\'s quotation', function () {
    quoteAtCounter($this->pos, $this->lines)->assertCreated();
    $other = userWithRole(Role::SalesStaff);

    $this->actingAs($other)->post(route('quotations.cancel', Quotation::sole()))->assertForbidden();
    expect(Sale::count())->toBe(0);
});
