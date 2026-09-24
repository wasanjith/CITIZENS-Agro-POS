<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Terminal;
use Database\Seeders\TerminalSeeder;

test('the sample thermal invoice renders Sinhala labels, product names, paid and balance', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->get(route('admin.printing-test.thermal', ['lang' => 'si']))
        ->assertOk()
        ->assertSee('සිටිසන්ස් ඇග්‍රෝ')
        ->assertSee('යූරියා පොහොර 50kg')
        ->assertSee('ගෙවිය යුතු මුදල')
        ->assertSee('ගෙවූ මුදල (මුදල්)')
        ->assertSee('ඉතිරිය')
        ->assertSee('24,180.00')
        ->assertSee('25,000.00')
        ->assertSee('820.00');
});

test('the sample invoice can be printed in English or both languages', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));

    $this->get(route('admin.printing-test.thermal', ['lang' => 'en']))
        ->assertOk()
        ->assertSee('Urea Fertilizer 50kg')
        ->assertDontSee('යූරියා පොහොර 50kg');

    $this->get(route('admin.printing-test.thermal', ['lang' => 'si+en']))
        ->assertOk()
        ->assertSee('ඉතිරිය / Balance');
});

test('auto-printing records the test time on the terminal printer', function () {
    $this->seed(TerminalSeeder::class);
    [$terminal, $token] = registeredTerminal(Terminal::where('code', 'C2')->firstOrFail());

    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->withCookie(deviceCookie(), $token)
        ->get(route('admin.printing-test.thermal', ['autoprint' => 1]))
        ->assertOk()
        ->assertSee('window.print()', false);

    expect($terminal->printer->fresh()->last_test_at)->not->toBeNull();
});

test('the printing test page is for the Super Admin only', function () {
    $this->actingAs(userWithRole(Role::SalesStaff))
        ->get(route('admin.printing-test'))
        ->assertForbidden();
});
