<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use Database\Seeders\TerminalSeeder;

beforeEach(function () {
    $this->seed(TerminalSeeder::class);
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->actingAs($this->owner);
});

test('every back-office page renders for the owner', function (string $route, array $parameters = []) {
    $parameters = array_map(fn ($value) => $value instanceof Closure ? $value() : $value, $parameters);

    $this->get(route($route, $parameters))->assertOk();
})->with([
    'dashboard' => ['dashboard'],
    'account' => ['account'],
    'users' => ['admin.users.index'],
    'user create' => ['admin.users.create'],
    'user edit' => ['admin.users.edit', ['user' => fn () => userWithRole(Role::SalesStaff)]],
    'terminals' => ['admin.terminals.index'],
    'terminal create' => ['admin.terminals.create'],
    'terminal edit' => ['admin.terminals.edit', ['terminal' => fn () => Terminal::firstOrFail()]],
    'terminal register' => ['admin.terminals.register', ['terminal' => fn () => Terminal::firstOrFail()]],
    'printers' => ['admin.printers.index'],
    'printer create' => ['admin.printers.create'],
    'printer edit' => ['admin.printers.edit', ['printer' => fn () => Printer::firstOrFail()]],
    'printing test' => ['admin.printing-test'],
    'settings shop' => ['admin.settings.edit', ['group' => 'shop']],
    'settings receipt' => ['admin.settings.edit', ['group' => 'receipt']],
    'settings tax' => ['admin.settings.edit', ['group' => 'tax']],
    'settings pos' => ['admin.settings.edit', ['group' => 'pos']],
    'settings inventory' => ['admin.settings.edit', ['group' => 'inventory']],
    'audit log' => ['admin.audit.index'],
]);

test('the audit log shows who changed what', function () {
    $terminal = Terminal::firstOrFail();
    $terminal->update(['name' => 'Front Counter']);

    $this->get(route('admin.audit.index'))
        ->assertOk()
        ->assertSee('Front Counter');
});

test('the dashboard reminds the owner to turn on two-factor authentication', function () {
    $this->get(route('dashboard'))->assertSee('Two-factor authentication is not turned on');
});

test('the terminals page shows which device is this one', function () {
    [, $token] = registeredTerminal(Terminal::where('code', 'MAIN')->firstOrFail());

    $this->withCookie(deviceCookie(), $token)
        ->get(route('admin.terminals.index'))
        ->assertSee('This browser is registered as <strong>Main Cashier</strong>', false);
});
