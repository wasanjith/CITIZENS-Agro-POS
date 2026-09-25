<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Actions\IssueCounterInvoiceAction;
use App\Domain\Sales\Actions\ReprintInvoiceAction;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\System\Services\Settings;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pos = posSetup();
    $urea = ureaInStock();

    $result = app(IssueCounterInvoiceAction::class)->handle(
        $this->pos['counter'],
        $this->pos['staff'],
        cartPayload([
            ['product_id' => $urea->id, 'unit_id' => unitId('bag'), 'qty' => '2', 'discount' => '250'],
        ], ['tendered' => '18000']),
        Str::random(32),
    );

    $this->sale = $result['sale'];
    $this->job = $result['print_job'];
});

test('the 80 mm invoice prints in Sinhala with totals, paid and balance', function () {
    app(Settings::class)->setGroup('shop', ['name_si' => 'සිටිසන්ස් ඇග්‍රෝ']);

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->get(route('pos.sales.invoice', ['sale' => $this->sale, 'job' => $this->job->id]))
        ->assertOk()
        ->assertSee('සිටිසන්ස් ඇග්‍රෝ')
        ->assertSee($this->sale->invoice_no)
        ->assertSee('යූරියා')                 // product name_si
        ->assertSee('ගෙවිය යුතු මුදල')          // total
        ->assertSee('17,750.00')
        ->assertSee('ගෙවූ මුදල (මුදල්)')        // paid (cash)
        ->assertSee('18,000.00')
        ->assertSee('ඉතිරිය')                  // balance
        ->assertSee('250.00')
        ->assertSee('window.print()', false)
        ->assertDontSee('පිටපත');
});

test('the invoice prints in English when the terminal is set to English', function () {
    $this->pos['counter']->update(['receipt_language' => 'en']);

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->get(route('pos.sales.invoice', ['sale' => $this->sale, 'job' => $this->job->id]))
        ->assertOk()
        ->assertSee('Balance')
        ->assertSee('Urea 50kg');
});

test('a reprint is marked COPY and a printed job does not print again', function () {
    $copy = app(ReprintInvoiceAction::class)->handle($this->sale, $this->pos['staff'], $this->pos['counter']);

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->get(route('pos.sales.invoice', ['sale' => $this->sale, 'job' => $copy->id]))
        ->assertSee('පිටපත')
        ->assertSee('COPY');

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->postJson(route('api.pos.print-jobs.printed', $this->job))
        ->assertOk();

    expect(PrintJob::find($this->job->id)->printed_at)->not->toBeNull();

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->get(route('pos.sales.invoice', ['sale' => $this->sale, 'job' => $this->job->id]))
        ->assertDontSee('window.print()', false);
});

test('another counter\'s staff cannot open the invoice', function () {
    [$counter2, $token2] = registeredTerminal(Terminal::factory()->counter(2)->create());
    $other = userWithRole(Role::SalesStaff);

    atTerminal($token2, $other)->get(route('pos.sales.invoice', $this->sale))->assertForbidden();
});

test('the printer test page prints and records the last test', function () {
    $printer = $this->pos['counter']->printer;

    $this->actingAs($this->pos['owner'])->post(route('admin.printers.test', $printer))->assertRedirect();
    $job = PrintJob::query()->latest('id')->first();

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->get(route('pos.printers.test-page', ['job' => $job->id]))
        ->assertOk()
        ->assertSee('ශ්‍රී')
        ->assertSee('window.print()', false);

    atTerminal($this->pos['counterToken'], $this->pos['staff'])->postJson(route('api.pos.print-jobs.printed', $job))->assertOk();

    expect($printer->refresh()->last_test_at)->not->toBeNull();
});
