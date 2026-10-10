<?php

use App\Domain\Identity\Actions\SaveUserAction;
use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

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

    $record = Activity::where('event', 'login')->where('causer_id', $user->id)->sole();
    expect($record->properties['method'])->toBe('pin')
        ->and($record->properties['terminal'])->toBe('C1')
        ->and($user->fresh()->last_login_at)->not->toBeNull();
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

test('an account with two-factor authentication cannot sign in with a PIN', function () {
    [, $token] = registeredTerminal();
    $owner = User::factory()->withPin('1111')->create(['name' => 'Owner With Code']);
    $owner->assignRole(Role::SuperAdmin->value);
    $owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

    $this->withCookie(deviceCookie(), $token)
        ->get(route('pin-login'))
        ->assertOk()
        ->assertDontSee('Owner With Code');

    $this->withCookie(deviceCookie(), $token)
        ->post(route('pin-login.store'), ['user_id' => $owner->id, 'pin' => '1111'])
        ->assertSessionHasErrors('pin');

    expect(session('errors')->first('pin'))->toContain('two-factor');
    $this->assertGuest();
});

test('too many wrong PINs in a day lock the PIN, are logged and tell the owner; a new PIN unlocks it', function () {
    [, $token] = registeredTerminal();
    $owner = userWithRole(Role::SuperAdmin);
    $user = staffWithPin('4321');
    $limit = config('pos.pin.max_failures_per_day');

    for ($i = 0; $i < $limit; $i++) {
        // Stay under the per-minute limit, as someone patient would.
        $this->travel(61)->seconds();
        $this->withCookie(deviceCookie(), $token)
            ->post(route('pin-login.store'), ['user_id' => $user->id, 'pin' => '0000']);
    }

    $this->travel(61)->seconds();
    $this->withCookie(deviceCookie(), $token)
        ->post(route('pin-login.store'), ['user_id' => $user->id, 'pin' => '4321'])
        ->assertSessionHasErrors('pin');

    expect(session('errors')->first('pin'))->toContain('locked for today')
        ->and(Activity::where('event', 'login_failed')->where('subject_id', $user->id)->count())->toBe($limit)
        ->and(Activity::where('event', 'pin_locked')->where('subject_id', $user->id)->count())->toBe(1)
        ->and($owner->notifications()->count())->toBe(1);
    $this->assertGuest();

    app(SaveUserAction::class)->handle(['name' => $user->name, 'username' => $user->username, 'role' => Role::SalesStaff->value, 'pin' => '5678'], $user);

    $this->withCookie(deviceCookie(), $token)
        ->post(route('pin-login.store'), ['user_id' => $user->id, 'pin' => '5678'])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});
