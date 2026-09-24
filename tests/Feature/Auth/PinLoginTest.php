<?php

use App\Domain\Identity\Enums\Role;
use App\Models\User;

function staffWithPin(string $pin = '4321', array $attributes = []): User
{
    return User::factory()->withPin($pin)->salesStaff()->create($attributes);
}

test('the PIN page lists users who have a PIN on a registered terminal', function () {
    [, $token] = registeredTerminal();
    $withPin = staffWithPin(attributes: ['name' => 'Kamal Perera']);
    userWithRole(Role::SalesStaff, ['name' => 'No Pin Person']);

    $this->withCookie(deviceCookie(), $token)
        ->get(route('pin-login'))
        ->assertOk()
        ->assertSee($withPin->name)
        ->assertDontSee('No Pin Person');
});

test('staff sign in with the correct PIN on a registered terminal', function () {
    [, $token] = registeredTerminal();
    $user = staffWithPin('4321');

    $this->withCookie(deviceCookie(), $token)
        ->post(route('pin-login.store'), ['user_id' => $user->id, 'pin' => '4321'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('a wrong PIN is rejected', function () {
    [, $token] = registeredTerminal();
    $user = staffWithPin('4321');

    $this->withCookie(deviceCookie(), $token)
        ->post(route('pin-login.store'), ['user_id' => $user->id, 'pin' => '9999'])
        ->assertSessionHasErrors('pin');

    $this->assertGuest();
});

test('PIN sign-in is refused on an unregistered device', function () {
    $user = staffWithPin('4321');

    $this->get(route('pin-login'))->assertRedirect(route('terminal.unregistered'));

    $this->post(route('pin-login.store'), ['user_id' => $user->id, 'pin' => '4321'])
        ->assertRedirect(route('terminal.unregistered'));

    $this->assertGuest();
});

test('a device token for an inactive terminal is not accepted', function () {
    [$terminal, $token] = registeredTerminal();
    $terminal->update(['is_active' => false]);
    $user = staffWithPin('4321');

    $this->withCookie(deviceCookie(), $token)
        ->post(route('pin-login.store'), ['user_id' => $user->id, 'pin' => '4321'])
        ->assertRedirect(route('terminal.unregistered'));

    $this->assertGuest();
});

test('inactive users cannot sign in with a PIN', function () {
    [, $token] = registeredTerminal();
    $user = staffWithPin('4321', ['is_active' => false]);

    $this->withCookie(deviceCookie(), $token)
        ->post(route('pin-login.store'), ['user_id' => $user->id, 'pin' => '4321'])
        ->assertSessionHasErrors('pin');

    $this->assertGuest();
});

test('PIN sign-in locks after too many failed attempts', function () {
    [, $token] = registeredTerminal();
    $user = staffWithPin('4321');
    $maxAttempts = config('pos.pin.max_attempts_per_minute');

    for ($i = 0; $i < $maxAttempts; $i++) {
        $this->withCookie(deviceCookie(), $token)
            ->post(route('pin-login.store'), ['user_id' => $user->id, 'pin' => '0000']);
    }

    $this->withCookie(deviceCookie(), $token)
        ->post(route('pin-login.store'), ['user_id' => $user->id, 'pin' => '4321'])
        ->assertSessionHasErrors('pin');

    expect(session('errors')->first('pin'))->toStartWith('Too many attempts');
    $this->assertGuest();
});
