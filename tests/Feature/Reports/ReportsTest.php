<?php

use App\Domain\Finance\Services\FinancialReports;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Reports\Jobs\ExportReportJob;
use App\Domain\Reports\Notifications\ReportExportReady;
use App\Domain\Reports\ReportRegistry;
use App\Domain\Reports\Services\DailySalesFigures;
use App\Domain\Reports\Services\DashboardData;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Sales\Actions\ReprintInvoiceAction;
use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Services\CounterEventRecorder;
use App\Domain\Sales\Support\Money;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Two settled cash sales of Urea (2 bags + 1 bag at Rs. 9000, cost Rs. 8000 a bag), a
 * 500 bill discount on the second, a return of 1 bag from the first and a voided invoice.
 */
beforeEach(function () {
    $this->pos = posSetup();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->urea = ureaInStock();
    openDrawer($this->pos['main'], $this->pos['owner']);

    $bag = unitId('bag');
    $this->first = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => $bag, 'qty' => '2']], ['tendered' => '20000']);
    cashierSettle($this->pos, $this->pos['owner'], $this->first)->assertOk();

    $this->second = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => $bag, 'qty' => '1']], ['tendered' => '9000', 'bill_discount' => '300']);
    cashierSettle($this->pos, $this->pos['owner'], $this->second)->assertOk();

    $item = $this->first->refresh()->items()->sole();
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->post(route('pos.returns.store', $this->first), [
        'reason' => 'Too much',
        'refund_method' => 'cash',
        'lines' => [$item->id => ['qty' => '1', 'restock' => '1']],
        'idempotency_key' => Str::random(32),
    ])->assertSessionHasNoErrors();

    $this->voided = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => $bag, 'qty' => '1']], ['tendered' => '9000']);
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->postJson(route('pos.sales.void', $this->voided), ['reason' => 'Wrong item'])->assertOk();
});

function reportRows(string $key, array $query = [], $user = null): array
{
    $report = app(ReportRegistry::class)->find($key);
    $input = ReportInput::fromQuery($report, $query, $user ?? test()->pos['owner']);

    return $report->run($input)->rows;
}

test('the owner opens the reports page and every report', function () {
    $supplier = Supplier::factory()->create(['name' => 'Agro Lanka', 'payment_terms_days' => 30]);
    directGrn($this->pos['owner'], $supplier, $this->urea, '4', '7800');
    $customer = creditCustomer();
    $credit = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['customer_id' => $customer->id, 'payment_method' => 'credit']);
    cashierSettle($this->pos, $this->pos['owner'], $credit, ['method' => 'credit'])->assertOk();

    $this->actingAs($this->pos['owner'])->get(route('reports.show', 'purchases-by-supplier'))->assertSee('Agro Lanka')->assertSee('31,200.00');
    $this->actingAs($this->pos['owner'])->get(route('reports.show', 'supplier-ageing'))->assertSee('Agro Lanka');
    $this->actingAs($this->pos['owner'])->get(route('reports.show', 'receivables-ageing'))->assertSee($customer->name)->assertSee('9,000.00');
    $this->actingAs($this->pos['owner'])->get(route('reports.show', 'credit-sales'))->assertSee($credit->invoice_no);

    $this->actingAs($this->pos['owner'])->get(route('reports.index'))->assertOk()->assertSee('Daily summary (Z report)')->assertSee('Profit & loss');

    foreach (array_keys(app(ReportRegistry::class)->all()) as $key) {
        $this->actingAs($this->pos['owner'])->get(route('reports.show', $key))->assertOk();
    }
});

test('the daily summary agrees with the books', function () {
    $day = collect(reportRows('daily-sales'))->sole();
    $books = app(FinancialReports::class)->profitAndLoss(today(), today());

    // 18000 + 8700 sold, one bag (9000) back, the voided invoice not counted.
    expect($day['invoices'])->toBe('2')
        ->and($day['sales'])->toBe('26700.00')
        ->and($day['returns'])->toBe('9000.00')
        ->and($day['net'])->toBe('17700.00')
        ->and($day['discount'])->toBe('300.00')
        ->and($day['cost'])->toBe('16000.00')
        ->and($day['profit'])->toBe('1700.00')
        ->and($day['pay_cash'])->toBe('17700.00')
        ->and($day['voids'])->toBe('1')
        ->and($day['void_value'])->toBe('9000.00')
        ->and($day['net'])->toBe((string) $books['net_sales'])
        ->and($day['profit'])->toBe((string) $books['gross_profit']);
});

test('sales and profit by item, counter and staff add up to the same net sales', function () {
    $item = collect(reportRows('sales-by-item'))->sole();
    expect($item['code'])->toBe('1001')
        ->and($item['qty_sold'])->toBe('150.000')
        ->and($item['qty_returned'])->toBe('50.000')
        ->and($item['net'])->toBe('17700.00')
        ->and($item['share'])->toBe('100.00');

    expect(collect(reportRows('sales-by-counter'))->sole())->toMatchArray(['name' => $this->pos['counter']->displayName(), 'invoices' => 2, 'net' => '17700.00'])
        ->and(collect(reportRows('sales-by-staff'))->sole())->toMatchArray(['name' => 'Nimal', 'net' => '17700.00'])
        ->and(collect(reportRows('sales-by-customer'))->sole()['name'])->toBe('Walk-in (no customer)');

    $profit = collect(reportRows('profit-by-item'))->sole();
    expect($profit['net_ex_tax'])->toBe('17700.00')
        ->and($profit['cost'])->toBe('16000.00')
        ->and($profit['profit'])->toBe('1700.00');
});

test('the nightly summary gives the same figures as the live tables', function () {
    $this->travelTo(now()->addDay());

    $figures = app(DailySalesFigures::class);
    $figures->rebuild(today()->subDays(3), today()->subDay());

    $live = $figures->live(today()->subDay(), today()->subDay());
    $stored = $figures->fromSummaries(today()->subDay(), today()->subDay());

    expect($stored->all())->toEqual($live->all())->and($stored)->toHaveCount(1);

    // A range longer than a year reads the summaries.
    $rows = reportRows('daily-sales', ['from' => today()->subYears(2)->toDateString(), 'to' => today()->toDateString()]);
    expect(collect($rows)->sole()['net'])->toBe('17700.00');
});

test('removed items, reprints and voids are counted per counter and staff member', function () {
    app(CounterEventRecorder::class)->record($this->pos['counter']->id, $this->pos['staff']->id, CounterEventType::ItemRemoved, (string) Str::uuid(), ['name' => 'Urea 50kg', 'qty' => '1', 'unit' => 'bag', 'line_total' => '9000.00']);
    app(ReprintInvoiceAction::class)->handle($this->first, $this->pos['staff'], $this->pos['counter']);

    $row = collect(reportRows('loss-by-counter'))->sole();

    expect($row)->toMatchArray(['staff' => 'Nimal', 'removed' => 1, 'removed_value' => '9000.00', 'reprints' => 1, 'voids' => 1, 'void_value' => '9000.00']);
    expect(collect(reportRows('counter-events'))->pluck('event')->all())->toContain('Removed')
        ->and(collect(reportRows('reprints'))->sole()['number'])->toBe($this->first->invoice_no);
});

test('inventory reports show stock and its value', function () {
    $stock = collect(reportRows('stock-valuation'))->sole();

    // 1000 kg − 150 sold + 50 returned = 900 kg at Rs. 160.
    expect($stock['on_hand'])->toBe('900.000')
        ->and($stock['value'])->toBe('144000.00');

    expect(reportRows('dead-stock'))->toBeEmpty()
        ->and(collect(reportRows('stock-movements'))->pluck('type')->unique()->values()->all())->toContain('Sale', 'Sale return');
});

test('reports follow the permissions', function () {
    $staff = $this->pos['staff'];
    $manager = $this->pos['manager'];

    $this->actingAs($staff)->get(route('reports.show', 'daily-sales'))->assertForbidden();
    $this->actingAs($staff)->get(route('reports.show', 'reorder-list'))->assertOk();
    $this->actingAs($manager)->get(route('reports.show', 'sales-by-item'))->assertOk();
    $this->actingAs($manager)->get(route('reports.show', 'profit-by-item'))->assertForbidden();
    $this->actingAs($manager)->get(route('reports.show', 'expenses'))->assertForbidden();
    $this->actingAs($manager)->get(route('reports.show', 'daily-sales'))->assertOk()->assertDontSee('Gross profit');
    $this->actingAs($this->pos['owner'])->get(route('reports.show', 'daily-sales'))->assertSee('Gross profit');
    $this->actingAs($manager)->get(route('reports.show', 'no-such-report'))->assertNotFound();
});

test('a report downloads as Excel and as PDF', function () {
    Excel::fake();
    $this->actingAs($this->pos['owner'])->get(route('reports.export', ['report' => 'sales-by-item', 'format' => 'xlsx']))->assertOk();
    Excel::assertDownloaded('sales-by-item-'.today()->startOfMonth()->format('Ymd').'-'.today()->format('Ymd').'.xlsx', fn ($export) => $export->headings()[0] === 'Code' && $export->array()[0][0] === '1001');

    Pdf::fake();
    $this->actingAs($this->pos['owner'])->get(route('reports.export', ['report' => 'daily-sales', 'format' => 'pdf']))->assertOk();
    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf) => $pdf->viewName === 'print.report-a4' && str_contains($pdf->getHtml(), '17,700.00'));
});

test('a background export is stored for its user and announced under the bell', function () {
    Storage::fake('local');
    Notification::fake();

    ExportReportJob::dispatchSync('sales-by-item', ['from' => today()->toDateString(), 'to' => today()->toDateString()], $this->pos['owner']->id);

    $notification = null;
    Notification::assertSentTo($this->pos['owner'], ReportExportReady::class, function (ReportExportReady $sent) use (&$notification) {
        $notification = $sent;

        return true;
    });

    Storage::disk('local')->assertExists(ExportReportJob::directory($this->pos['owner']->id).'/'.$notification->file);
    $this->actingAs($this->pos['owner'])->get($notification->url())->assertOk();
    $this->actingAs($this->pos['manager'])->get($notification->url())->assertNotFound();
});

test('a return is counted on its own day against the original sale', function () {
    $this->travelTo(now()->addDay());
    $bag = unitId('bag');
    $sale = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => $bag, 'qty' => '1']], ['tendered' => '9000']);
    cashierSettle($this->pos, $this->pos['owner'], $sale)->assertOk();

    $rows = collect(reportRows('daily-sales', ['from' => today()->subDay()->toDateString(), 'to' => today()->toDateString()]))->keyBy('date');

    expect($rows[today()->subDay()->toDateString()]['net'])->toBe('17700.00')
        ->and($rows[today()->toDateString()]['net'])->toBe('9000.00')
        ->and(Sale::query()->count())->toBe(4);
});

test('the owner dashboard shows sales, profit, payments, stock, purchases, supplier bills and cash', function () {
    $this->actingAs($this->pos['owner'])->get(route('dashboard'))
        ->assertOk()
        ->assertSee("Today's total sales")
        ->assertSee('Rs. 17,700.00')
        ->assertSee('Gross profit today')
        ->assertSee('Rs. 1,700.00')
        ->assertSee('Gross profit this month')
        ->assertSee('Sales this month')
        ->assertSee('Total items')
        ->assertSee('Payment summary')
        ->assertSee('Low stock items')
        ->assertSee('Best selling items (today)')
        ->assertSee('Urea 50kg')
        ->assertSee('Sales by counter')
        ->assertSee('Pending purchases')
        ->assertSee('Recent sales')
        ->assertSee($this->first->invoice_no)
        ->assertSee('Pending payments (suppliers)')
        ->assertSee('Cash summary (today)')
        ->assertSee('Closing cash')
        ->assertSee('Customers owe')
        ->assertSee('Quick actions')
        ->assertSee('Add expense');
});

test('the manager dashboard shows sales, stock and purchasing but no profit or money from the books', function () {
    $this->actingAs($this->pos['manager'])->get(route('dashboard'))
        ->assertOk()
        ->assertSee("Today's total sales")
        ->assertSee('Low stock items')
        ->assertSee('Pending purchases')
        ->assertSee('Recent sales')
        ->assertDontSee('Gross profit')
        ->assertDontSee('Customers owe')
        ->assertDontSee('Pending payments (suppliers)')
        ->assertDontSee('Cash summary (today)');
});

test('sales staff see their device and shortcuts, not the shop figures', function () {
    $this->actingAs($this->pos['staff'])->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee("Today's total sales")
        ->assertDontSee('Payment summary')
        ->assertDontSee('Cash summary (today)')
        ->assertSee('This device')
        ->assertSee('New purchase order');
});

test('the dashboard figures: yesterday, month, profit, payments, best sellers and cash', function () {
    $data = app(DashboardData::class);

    // 2 bags + 1 bag less a 300 discount, 1 bag returned; cost Rs. 8000 a bag.
    expect($data->todaysSales())->toMatchArray(['net' => '17700.00', 'invoices' => 2, 'yesterday' => '0.00', 'change' => null])
        ->and($data->monthToDate())->toMatchArray(['net' => '17700.00', 'invoices' => 2])
        ->and($data->profit()['today'])->toBe(['profit' => '1700.00', 'margin' => '9.60'])
        ->and($data->profit()['month'])->toBe(['profit' => '1700.00', 'margin' => '9.60']);

    $payments = collect($data->paymentsToday()['methods'])->keyBy('method');
    expect($payments->keys()->all())->toBe(['cash', 'card', 'bank_transfer'])
        ->and($payments['card']['amount'])->toBe('0.00')
        ->and($data->paymentsToday()['total'])->toBe($payments['cash']['amount']);

    expect($data->bestSellersToday())->toBe([['id' => $this->urea->id, 'name' => 'Urea 50kg', 'qty' => '100', 'unit' => 'kg']])
        ->and(collect($data->recentSales())->pluck('invoice')->all())->toBe([$this->second->invoice_no, $this->first->invoice_no]);

    $cash = $data->drawer()['summary'];
    expect($cash['expected'])->toBe((string) Money::of($cash['opening'])->plus($cash['sales'])->plus($cash['cash_in'])->minus($cash['cash_out']))
        ->and($cash['cash_out'])->toBe('9000.00');

    $this->travelTo(now()->addDay());
    expect(app(DashboardData::class)->todaysSales())->toMatchArray(['net' => '0.00', 'yesterday' => '17700.00', 'change' => '-100.00']);
});

test('unpaid supplier bills are overdue, due soon (within 7 days) or not due', function () {
    $supplier = Supplier::factory()->create(['name' => 'Agro Lanka', 'payment_terms_days' => 30]);
    $bill = fn (string $number, int $daysAgo, string $total, string $paid = '0') => GoodsReceipt::query()->forceCreate([
        'number' => $number, 'supplier_id' => $supplier->id, 'received_at' => now()->subDays($daysAgo), 'status' => GoodsReceiptStatus::Posted,
        'subtotal' => $total, 'total' => $total, 'amount_paid' => $paid,
    ]);
    $bill('GRN-1', 40, '50000.00', '20000.00');
    $bill('GRN-2', 25, '30000.00');
    $bill('GRN-3', 10, '10000.00');
    $bill('GRN-4', 45, '5000.00', '5000.00');

    $dues = app(DashboardData::class)->supplierDues();

    expect(collect($dues['bills'])->map(fn (array $bill) => [$bill['invoice'], $bill['balance'], $bill['status']])->all())->toBe([
        ['GRN-1', '30000.00', 'overdue'],
        ['GRN-2', '30000.00', 'due_soon'],
        ['GRN-3', '10000.00', 'not_due'],
    ])->and($dues['outstanding'])->toBe('70000.00')
        ->and($dues['overdue'])->toBe(1);

    $this->actingAs($this->pos['owner'])->get(route('dashboard'))
        ->assertSee('Agro Lanka')
        ->assertSee('Overdue')
        ->assertSee('Due soon')
        ->assertSee('Rs. 70,000.00')
        ->assertSee('1 supplier bill(s) overdue');
});
