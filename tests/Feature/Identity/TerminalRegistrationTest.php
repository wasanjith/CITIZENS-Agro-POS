<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Policies\PrinterPolicy;
use App\Domain\Identity\Policies\TerminalPolicy;
use App\Domain\Identity\Services\TerminalRegistrar;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

test('the Super Admin registers the current browser as a terminal', function () {
    $owner = userWithRole(Role::SuperAdmin);
    $terminal = Terminal::factory()->mainCashier()->create();

    $response = $this->actingAs($owner)
        ->post(route('admin.terminals.register.store', $terminal))
        ->assertRedirect(route('admin.terminals.index'))
        ->assertCookie(deviceCookie());

    $terminal->refresh();
    $token = $response->getCookie(deviceCookie(), decrypt: true)->getValue();

    expect($terminal->isRegistered())->toBeTrue()
        ->and($terminal->registered_by)->toBe($owner->id)
        ->and($terminal->device_token_hash)->toBe(TerminalRegistrar::hash($token))
        ->and($terminal->device_token_hash)->not->toBe($token);
});

test('registering a terminal again signs off the previous device', function () {
    [$terminal, $oldToken] = registeredTerminal();
    $newToken = app(TerminalRegistrar::class)->register($terminal, userWithRole(Role::SuperAdmin));

    expect(app(TerminalRegistrar::class)->resolve($oldToken))->toBeNull()
        ->and(app(TerminalRegistrar::class)->resolve($newToken)?->is($terminal))->toBeTrue();
});

test('unregistering a terminal stops its device from working', function () {
    $owner = userWithRole(Role::SuperAdmin);
    [$terminal, $token] = registeredTerminal();

    $this->actingAs($owner)
        ->delete(route('admin.terminals.register.destroy', $terminal))
        ->assertRedirect(route('admin.terminals.index'));

    expect($terminal->fresh()->isRegistered())->toBeFalse()
        ->and(app(TerminalRegistrar::class)->resolve($token))->toBeNull();
});

test('terminal and printer policies are attached to their models', function () {
    expect(Gate::getPolicyFor(Terminal::class))->toBeInstanceOf(TerminalPolicy::class)
        ->and(Gate::getPolicyFor(Printer::class))->toBeInstanceOf(PrinterPolicy::class);
});

test('only the Super Admin can manage terminals', function (Role $role) {
    $terminal = Terminal::factory()->create();

    $this->actingAs(userWithRole($role));

    $this->get(route('admin.terminals.index'))->assertForbidden();
    $this->post(route('admin.terminals.register.store', $terminal))->assertForbidden();
})->with([Role::Manager, Role::SalesStaff]);

test('terminal-type middleware allows only the matching terminal', function () {
    Route::middleware(['web', 'terminal:main_cashier'])->get('/_test/main-only', fn () => 'ok');

    [, $counterToken] = registeredTerminal(Terminal::factory()->counter(2)->create());
    [, $mainToken] = registeredTerminal(Terminal::factory()->mainCashier()->create());

    $this->withCookie(deviceCookie(), $counterToken)->get('/_test/main-only')->assertForbidden();
    $this->withCookie(deviceCookie(), $mainToken)->get('/_test/main-only')->assertOk();
});

test('the Super Admin creates and edits terminals', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));

    $this->post(route('admin.terminals.store'), [
        'name' => 'Counter 4', 'code' => 'c4', 'type' => 'counter', 'counter_no' => 4, 'is_active' => '1',
    ])->assertRedirect(route('admin.terminals.index'));

    $terminal = Terminal::where('code', 'C4')->firstOrFail();
    expect($terminal->counter_no)->toBe(4);

    $this->put(route('admin.terminals.update', $terminal), [
        'name' => 'Counter 5', 'code' => 'C5', 'type' => 'counter', 'counter_no' => 5, 'is_active' => '1',
    ])->assertRedirect(route('admin.terminals.index'));

    expect($terminal->fresh()->code)->toBe('C5');

    // Counter numbers are unique per terminal.
    Terminal::factory()->counter(1)->create();
    $this->put(route('admin.terminals.update', $terminal), [
        'name' => 'Counter 5', 'code' => 'C5', 'type' => 'counter', 'counter_no' => 1, 'is_active' => '1',
    ])->assertSessionHasErrors('counter_no');
});
