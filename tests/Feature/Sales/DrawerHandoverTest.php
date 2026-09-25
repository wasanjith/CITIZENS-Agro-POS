<?php

use App\Domain\CashDrawer\Actions\CloseDrawerAction;
use App\Domain\CashDrawer\Actions\RecordCashMovementAction;
use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\CashDrawer\Enums\DrawerCloseReason;
use App\Domain\CashDrawer\Jobs\ProcessExpiredDelegationsJob;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Identity\Models\Delegation;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Identity\Services\DelegationService;
use App\Domain\Sales\Actions\IssueCounterInvoiceAction;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->pos = posSetup();
    $this->urea = ureaInStock();
});

function bill(array $pos, $urea, string $bags = '1', ?Terminal $terminal = null): Sale
{
    return app(IssueCounterInvoiceAction::class)->handle(
        $terminal ?? $pos['counter'],
        $pos['staff'],
        cartPayload([['product_id' => $urea->id, 'unit_id' => unitId('bag'), 'qty' => $bags]], ['tendered' => (string) (9000 * (int) $bags)]),
        Str::random(32),
    )['sale'];
}

function settle(array $pos, $user, Sale $sale): void
{
    atTerminal($pos['mainToken'], $user)
        ->postJson(route('api.pos.sales.settle', $sale), ['idempotency_key' => Str::random(32)])
        ->assertOk();
}

function handoverForm(array $pos, array $overrides = []): array
{
    return [
        'denominations' => ['5000' => 2, '1000' => 3],
        'manager_id' => $pos['manager']->id,
        'manager_pin' => '2222',
        'manager_counted' => '13000',
        'expires_at' => now()->addHours(3)->format('Y-m-d H:i'),
        'reason' => 'Owner at the bank',
        'permissions' => ['pos.settle', 'pos.live_view', 'pos.void', 'drawer.manage', 'pos.approve_requests'],
        ...$overrides,
    ];
}

test('opening float, pay in, pay out and safe drop give the expected cash', function () {
    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->post(route('pos.drawer.store'), ['denominations' => ['1000' => 4, '100' => 10, 'coins' => '0']])
        ->assertRedirect(route('pos.cashier'));

    $session = DrawerSession::sole();
    expect($session->opening_float)->toBe('5000.00');

    settle($this->pos, $this->pos['owner'], bill($this->pos, $this->urea));

    foreach ([['pay_in', '1500'], ['pay_out', '400'], ['safe_drop', '10000']] as [$type, $amount]) {
        atTerminal($this->pos['mainToken'], $this->pos['owner'])
            ->post(route('pos.drawer.movements.store'), ['type' => $type, 'amount' => $amount, 'reason' => 'Test'])
            ->assertRedirect(route('pos.drawer.show'));
    }

    // 5000 + 9000 + 1500 − 400 − 10000
    expect((string) app(DrawerCalculator::class)->expectedCash($session))->toBe('5100.00');

    expect(fn () => app(RecordCashMovementAction::class)->handle($session, $this->pos['owner'], CashMovementType::PayOut, '6000', 'Too much'))
        ->toThrow(ValidationException::class);
});

test('only one drawer session can be open per terminal (database constraint)', function () {
    openDrawer($this->pos['main'], $this->pos['owner']);

    expect(fn () => DrawerSession::create([
        'terminal_id' => $this->pos['main']->id,
        'holder_user_id' => $this->pos['manager']->id,
        'opened_at' => now(),
        'opening_float' => '0',
        'is_open' => true,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('handover: session A closed with the right expected cash, session B opened with the counted amount, delegate settles the waiting invoice', function () {
    $session = openDrawer($this->pos['main'], $this->pos['owner'], 4);
    settle($this->pos, $this->pos['owner'], bill($this->pos, $this->urea));
    $waiting = bill($this->pos, $this->urea);

    // Expected 4000 + 9000 = 13000; counted 13000.
    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->post(route('pos.handover.store'), handoverForm($this->pos))
        ->assertRedirect(route('pos.cashier'));

    $a = $session->refresh();
    expect($a->close_reason)->toBe(DrawerCloseReason::Handover)
        ->and($a->expected_cash)->toBe('13000.00')
        ->and($a->counted_cash)->toBe('13000.00')
        ->and($a->variance)->toBe('0.00');

    $b = DrawerSession::query()->open()->sole();
    expect($b->holder_user_id)->toBe($this->pos['manager']->id)
        ->and($b->opening_float)->toBe('13000.00')
        ->and($b->previous_session_id)->toBe($a->id);

    $delegation = Delegation::sole();
    expect($delegation->drawer_session_id)->toBe($b->id)
        ->and($delegation->permissions)->toContain('pos.settle')
        ->and(app(CashierAuthority::class)->holder()->is($this->pos['manager']))->toBeTrue();

    // The terminal is now signed in as the Manager, who settles the invoice left over.
    $this->assertAuthenticatedAs($this->pos['manager']);
    expect($waiting->refresh()->status)->toBe(SaleStatus::Invoiced);
    settle($this->pos, $this->pos['manager'], $waiting);
    expect($waiting->refresh()->drawer_session_id)->toBe($b->id);
});

test('a handover is refused when the Manager counts a different amount, or the PIN is wrong', function () {
    openDrawer($this->pos['main'], $this->pos['owner'], 13);

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->post(route('pos.handover.store'), handoverForm($this->pos, ['manager_counted' => '12500']))
        ->assertSessionHasErrors(['manager_counted' => 'The counts differ by Rs. 500.00 (owner Rs. 13,000.00, Manager Rs. 12,500.00). Count the drawer again together.']);

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->post(route('pos.handover.store'), handoverForm($this->pos, ['manager_pin' => '9999']))
        ->assertSessionHasErrors('manager_pin');

    expect(DrawerSession::query()->open()->sole()->holder_user_id)->toBe($this->pos['owner']->id)
        ->and(Delegation::count())->toBe(0);
});

test('take back: the Manager counts, the delegation is revoked and a new session opens for the owner', function () {
    openDrawer($this->pos['main'], $this->pos['owner'], 13);
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->post(route('pos.handover.store'), handoverForm($this->pos))->assertRedirect();
    settle($this->pos, $this->pos['manager'], bill($this->pos, $this->urea));

    // Drawer should hold 13000 + 9000 = 22000; the Manager counts 21900.
    atTerminal($this->pos['mainToken'], $this->pos['manager'])
        ->post(route('pos.handover.return.store'), [
            'denominations' => ['5000' => 4, '1000' => 1, '100' => 9],
            'new_holder_id' => $this->pos['owner']->id,
            'new_holder_pin' => '1111',
        ])
        ->assertRedirect(route('pos.cashier'));

    $b = DrawerSession::query()->where('holder_user_id', $this->pos['manager']->id)->sole();
    expect($b->expected_cash)->toBe('22000.00')
        ->and($b->variance)->toBe('-100.00')
        ->and(Delegation::sole()->revoked_at)->not->toBeNull()
        ->and($this->pos['manager']->fresh()->can('pos.settle'))->toBeFalse();

    $c = DrawerSession::query()->open()->sole();
    expect($c->holder_user_id)->toBe($this->pos['owner']->id)
        ->and($c->opening_float)->toBe('21900.00')
        ->and($c->previous_session_id)->toBe($b->id);
    $this->assertAuthenticatedAs($this->pos['owner']);
});

test('remote revoke blocks the Manager, who can still count and close the drawer', function () {
    openDrawer($this->pos['main'], $this->pos['owner'], 13);
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->post(route('pos.handover.store'), handoverForm($this->pos))->assertRedirect();
    $sale = bill($this->pos, $this->urea);

    // Owner revokes from their phone (not a terminal).
    $this->actingAs($this->pos['owner'])
        ->post(route('admin.delegations.revoke', Delegation::sole()))
        ->assertRedirect();

    atTerminal($this->pos['mainToken'], $this->pos['manager'])
        ->postJson(route('api.pos.sales.settle', $sale), ['idempotency_key' => Str::random(32)])
        ->assertStatus(409);
    atTerminal($this->pos['mainToken'], $this->pos['manager'])->get(route('pos.cashier'))->assertRedirect(route('pos.authority-ended'));

    atTerminal($this->pos['mainToken'], $this->pos['manager'])
        ->post(route('pos.handover.return.store'), ['denominations' => ['5000' => 2, '1000' => 3]])
        ->assertRedirect(route('pos.drawer.show'));

    expect(DrawerSession::query()->open()->count())->toBe(0);

    // The owner opens the drawer later; the new session continues the day's chain.
    $b = DrawerSession::query()->where('holder_user_id', $this->pos['manager']->id)->sole();
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->post(route('pos.drawer.store'), ['denominations' => ['5000' => 2, '1000' => 3]])->assertRedirect();
    expect(DrawerSession::query()->open()->sole()->previous_session_id)->toBe($b->id);
});

test('the expiry job announces expired delegations once and notifies the owner', function () {
    openDrawer($this->pos['main'], $this->pos['owner'], 13);
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->post(route('pos.handover.store'), handoverForm($this->pos, ['expires_at' => now()->addMinutes(10)->format('Y-m-d H:i')]))->assertRedirect();

    $this->travel(11)->minutes();
    (new ProcessExpiredDelegationsJob)->handle(app(CashierAuthority::class), app(DelegationService::class));
    (new ProcessExpiredDelegationsJob)->handle(app(CashierAuthority::class), app(DelegationService::class));

    expect(Delegation::sole()->expiry_processed_at)->not->toBeNull()
        ->and($this->pos['owner']->notifications()->count())->toBe(1);
});

test('the Z report covers the whole day across a handover and balances per counter', function () {
    [$counter2, $token2] = registeredTerminal(Terminal::factory()->counter(2)->create());

    openDrawer($this->pos['main'], $this->pos['owner'], 5);
    settle($this->pos, $this->pos['owner'], bill($this->pos, $this->urea, '2'));            // C1 18000
    settle($this->pos, $this->pos['owner'], bill($this->pos, $this->urea, '1', $counter2)); // C2 9000
    $voided = bill($this->pos, $this->urea, '1', $counter2);
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->postJson(route('pos.sales.void', $voided), ['reason' => 'Mistake'])->assertOk();

    // Hand over with 5000 + 27000 = 32000.
    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->post(route('pos.handover.store'), handoverForm($this->pos, ['denominations' => ['5000' => 6, '1000' => 2], 'manager_counted' => '32000']))
        ->assertRedirect();
    settle($this->pos, $this->pos['manager'], bill($this->pos, $this->urea, '1')); // C1 9000

    $b = DrawerSession::query()->open()->sole();
    $closed = app(CloseDrawerAction::class)->handle($b, $this->pos['manager'], ['5000' => 8, '1000' => 1], DrawerCloseReason::EndOfDay);

    $report = app(DrawerCalculator::class)->zReport($closed);

    expect($report['sessions'])->toHaveCount(2)
        ->and($report['sales_total'])->toBe('36000.00')
        ->and($report['sales_count'])->toBe(3)
        ->and(collect($report['per_counter'])->pluck('total', 'terminal')->all())->toBe(['Counter 1' => '27000.00', 'Counter 2' => '9000.00'])
        ->and($report['void_count'])->toBe(1)
        ->and($report['expected_cash'])->toBe('41000.00')
        ->and($report['variance'])->toBe('0.00');

    // The 80 mm Z report and the A4 PDF view render.
    atTerminal($this->pos['mainToken'], $this->pos['manager'])
        ->get(route('pos.drawer.report', ['drawerSession' => $closed, 'print' => 1]))
        ->assertOk()
        ->assertSee('36,000.00')
        ->assertSee('Counter 2');
});
