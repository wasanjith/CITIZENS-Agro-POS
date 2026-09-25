<?php

use App\Domain\Identity\Actions\CreateDelegationAction;
use App\Domain\Sales\Actions\IssueCounterInvoiceAction;
use App\Domain\Sales\Models\Sale;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pos = posSetup();
    $this->urea = ureaInStock();
});

function printedSale(array $pos, $urea): Sale
{
    return app(IssueCounterInvoiceAction::class)->handle($pos['counter'], $pos['staff'], cartPayload([['product_id' => $urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['tendered' => '10000']), Str::random(32))['sale'];
}

test('back-office POS pages render for the owner', function () {
    $sale = printedSale($this->pos, $this->urea);
    $session = openDrawer($this->pos['main'], $this->pos['owner']);

    $this->actingAs($this->pos['owner']);

    foreach ([
        route('sales.index'),
        route('sales.show', $sale),
        route('admin.delegations.index'),
        route('admin.drawer-sessions.index'),
        route('admin.print-jobs.index'),
        route('admin.printers.index'),
        route('admin.live-billing'),
        route('pos.drawer.report', $session),
        route('dashboard'),
    ] as $url) {
        $this->get($url)->assertOk();
    }

    $this->get(route('sales.show', $sale))->assertSee($sale->invoice_no)->assertSee('<th class="text-right">Cost</th>', false);
});

test('main-terminal pages render: drawer, open, close, handover, hand back', function () {
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->get(route('pos.drawer.show'))->assertOk()->assertSee('Open drawer');
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->get(route('pos.drawer.open'))->assertOk()->assertSee('Opening float');
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->get(route('pos.cashier'))->assertRedirect(route('pos.drawer.open'));

    openDrawer($this->pos['main'], $this->pos['owner']);

    foreach (['pos.drawer.show', 'pos.drawer.close', 'pos.handover.create', 'pos.handover.return', 'pos.cashier'] as $name) {
        atTerminal($this->pos['mainToken'], $this->pos['owner'])->get(route($name))->assertOk();
    }
});

test('the drawer pages are only on the main terminal', function () {
    atTerminal($this->pos['counterToken'], $this->pos['owner'])->get(route('pos.drawer.show'))->assertForbidden();
    atTerminal($this->pos['counterToken'], $this->pos['owner'])->get(route('pos.cashier'))->assertForbidden();
});

test('staff see the sale they printed but not the back-office list or costs', function () {
    $sale = printedSale($this->pos, $this->urea);

    $this->actingAs($this->pos['staff'])->get(route('sales.index'))->assertForbidden();
    $this->actingAs($this->pos['staff'])->get(route('sales.show', $sale))->assertOk()->assertDontSee('<th class="text-right">Cost</th>', false);
    $this->actingAs($this->pos['staff'])->get(route('admin.delegations.index'))->assertForbidden();
});

test('a delegated Manager sees the cashier screen with their drawer', function () {
    app(CreateDelegationAction::class)->handle($this->pos['owner'], $this->pos['manager'], now()->addHours(2));
    openDrawer($this->pos['main'], $this->pos['manager']);

    atTerminal($this->pos['mainToken'], $this->pos['manager'])->get(route('pos.cashier'))->assertOk()->assertSee('Hand back');
});
