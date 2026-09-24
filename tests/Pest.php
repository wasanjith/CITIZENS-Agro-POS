<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Services\TerminalRegistrar;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against the MySQL test database (citizensDB_testing),
| because row locking and FULLTEXT behave differently on SQLite.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function userWithRole(Role $role, array $attributes = []): User
{
    // Refresh so columns filled by database defaults exist (models are strict outside production).
    return User::factory()->withRole($role)->create($attributes)->refresh();
}

/**
 * Create a terminal and register a device for it.
 *
 * @return array{0: Terminal, 1: string} the terminal and the plain device token
 */
function registeredTerminal(?Terminal $terminal = null): array
{
    $terminal ??= Terminal::factory()->counter(1)->create();
    $token = app(TerminalRegistrar::class)->register($terminal, User::factory()->create());

    return [$terminal->refresh(), $token];
}

function deviceCookie(): string
{
    return config('pos.device_cookie');
}
