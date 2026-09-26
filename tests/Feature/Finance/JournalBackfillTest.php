<?php

use App\Domain\CashDrawer\Actions\CloseDrawerAction;
use App\Domain\CashDrawer\Enums\DrawerCloseReason;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\JournalLine;
use App\Domain\Finance\Services\FinancialReports;
use App\Domain\Purchasing\Models\Supplier;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pos = posSetup();
    $this->urea = ureaInStock();
    $session = openDrawer($this->pos['main'], $this->pos['owner']);
    $customer = creditCustomer();

    directGrn($this->pos['owner'], Supplier::factory()->create(), $this->urea, '2', '8500');

    $cash = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['tendered' => '10000']);
    cashierSettle($this->pos, $this->pos['owner'], $cash)->assertOk();

    $credit = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '2']], ['customer_id' => $customer->id, 'payment_method' => 'credit']);
    cashierSettle($this->pos, $this->pos['owner'], $credit)->assertOk();

    $voided = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['tendered' => '9000']);
    cashierSettle($this->pos, $this->pos['owner'], $voided)->assertOk();
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->post(route('pos.sales.void', $voided), ['reason' => 'Wrong'])->assertSessionHasNoErrors();

    atTerminal($this->pos['mainToken'], $this->pos['owner'])->post(route('pos.customer-payments.store', $customer), [
        'amount' => '5000', 'method' => 'cash', 'allocation_mode' => 'fifo', 'idempotency_key' => Str::random(32),
    ])->assertSessionHasNoErrors();

    app(CloseDrawerAction::class)->handle($session->refresh(), $this->pos['owner'], ['5000' => 3, '1000' => 4], DrawerCloseReason::EndOfDay);
});

function trialBalanceRows(): array
{
    return collect(app(FinancialReports::class)->trialBalance(today())['rows'])
        ->mapWithKeys(fn (array $row) => [$row['account']->code => [$row['debit'], $row['credit']]])
        ->all();
}

test('the backfill posts documents from before Finance and gives the same books; running it again changes nothing', function () {
    $expected = trialBalanceRows();
    $entries = JournalEntry::count();

    // As if Phases 2–4 ran without Finance: no journal at all.
    JournalLine::query()->delete();
    JournalEntry::query()->whereNotNull('reverses_id')->delete();
    JournalEntry::query()->delete();

    $this->artisan('finance:backfill-journals')->assertSuccessful();

    expect(trialBalanceRows())->toBe($expected)
        ->and(JournalEntry::count())->toBe($entries);

    $this->artisan('finance:backfill-journals')->assertSuccessful();

    expect(JournalEntry::count())->toBe($entries);
    expectBooksBalance();
});
