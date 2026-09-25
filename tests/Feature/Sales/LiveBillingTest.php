<?php

use App\Domain\Sales\Actions\IssueCounterInvoiceAction;
use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Models\CounterEvent;
use App\Domain\Sales\Services\LiveCartStore;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pos = posSetup();
    $this->urea = ureaInStock();
    $this->bag = unitId('bag');
    $this->kg = unitId('kg');
    $this->uuid = (string) Str::uuid();
});

function syncCart(array $pos, array $lines, array $extra = [])
{
    return atTerminal($pos['counterToken'], $pos['staff'])
        ->postJson(route('api.pos.cart.sync'), cartPayload($lines, $extra));
}

test('cart sync stores the repriced cart and turns each change into counter events', function () {
    $urea = ['key' => 'a', 'product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '1'];
    $loose = ['key' => 'b', 'product_id' => $this->urea->id, 'unit_id' => $this->kg, 'qty' => '5'];

    syncCart($this->pos, [$urea], ['cart_uuid' => $this->uuid])
        ->assertOk()
        ->assertJsonPath('cart.total', '9000.00')
        ->assertJsonPath('cart.status', 'billing');

    syncCart($this->pos, [[...$urea, 'qty' => '2'], $loose], ['cart_uuid' => $this->uuid]);
    syncCart($this->pos, [[...$urea, 'qty' => '2']], ['cart_uuid' => $this->uuid, 'tendered' => '20000'])
        ->assertJsonPath('cart.status', 'payment')
        ->assertJsonPath('cart.change_due', '2000.00');
    syncCart($this->pos, [], ['cart_uuid' => $this->uuid]);

    expect(CounterEvent::orderBy('id')->pluck('type')->map->value->all())->toBe([
        CounterEventType::ItemAdded->value,
        CounterEventType::QtyChanged->value,
        CounterEventType::ItemAdded->value,
        CounterEventType::ItemRemoved->value,
        CounterEventType::Tendered->value,
        CounterEventType::CartCleared->value,
    ]);

    $removed = CounterEvent::where('type', CounterEventType::ItemRemoved)->sole();
    expect($removed->payload['qty'])->toBe('5')
        ->and($removed->payload['unit'])->toBe('kg')
        ->and($removed->terminal_id)->toBe($this->pos['counter']->id)
        ->and($removed->user_id)->toBe($this->pos['staff']->id);
});

test('the stored cart is restored after a refresh', function () {
    syncCart($this->pos, [['key' => 'a', 'product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '3']], ['cart_uuid' => $this->uuid]);

    atTerminal($this->pos['counterToken'], $this->pos['staff'])
        ->getJson(route('api.pos.cart.show'))
        ->assertOk()
        ->assertJsonPath('cart.cart_uuid', $this->uuid)
        ->assertJsonPath('cart.lines.0.qty', '3.000')
        ->assertJsonPath('cart.lines.0.units.0.symbol', 'kg');
});

test('the live-billing channel refuses users without pos.live_view', function () {
    // Channels are registered on the default broadcaster at boot (null in tests).
    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');

    $auth = fn ($user, string $channel) => $this->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

    $auth($this->pos['staff'], 'private-live-billing')->assertForbidden();
    $auth($this->pos['manager'], 'private-live-billing')->assertForbidden();
    $auth($this->pos['owner'], 'private-live-billing')->assertOk();
});

test('the snapshot shows carts, waiting invoices and today\'s totals without any cost', function () {
    syncCart($this->pos, [['key' => 'a', 'product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '1']], ['cart_uuid' => $this->uuid]);
    app(IssueCounterInvoiceAction::class)->handle($this->pos['counter'], $this->pos['staff'], cartPayload([['product_id' => $this->urea->id, 'unit_id' => $this->bag, 'qty' => '2']], ['tendered' => '18000']), Str::random(32));

    $this->actingAs($this->pos['staff'])->getJson(route('api.live-billing.snapshot'))->assertForbidden();

    $response = $this->actingAs($this->pos['owner'])->getJson(route('api.live-billing.snapshot'))->assertOk();

    $counter = collect($response->json('counters'))->firstWhere('terminal_id', $this->pos['counter']->id);
    expect($counter['online'])->toBeTrue()
        ->and($counter['user'])->toBe('Nimal')
        ->and($counter['invoices'])->toHaveCount(1)
        ->and($counter['invoices'][0]['total'])->toBe('18000.00')
        ->and($response->json('totals.waiting'))->toBe(1);

    $json = $response->getContent();
    expect($json)->not->toContain('cost')
        ->and($json)->not->toContain('reference_cost');

    // The printed invoice cleared the counter's live cart.
    expect(app(LiveCartStore::class)->get($this->pos['counter']->id))->toBeNull();
});

test('the view-only Live Billing page and the cashier screen render', function () {
    $this->actingAs($this->pos['owner'])->get(route('admin.live-billing'))->assertOk()->assertSee('liveBilling', false);
    $this->actingAs($this->pos['staff'])->get(route('admin.live-billing'))->assertForbidden();

    openDrawer($this->pos['main'], $this->pos['owner']);
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->get(route('pos.cashier'))->assertOk()->assertSee('liveBilling', false);
});
