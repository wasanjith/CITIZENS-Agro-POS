<?php

use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('the login page renders', function () {
    $this->get(route('login'))->assertOk()->assertSee('Sign in');
});

test('users sign in with username and password', function () {
    $user = userWithRole(Role::Manager, ['username' => 'nimal']);

    $this->post(route('login'), ['username' => 'Nimal', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test('a wrong password is rejected', function () {
    userWithRole(Role::Manager, ['username' => 'nimal']);

    $this->post(route('login'), ['username' => 'nimal', 'password' => 'wrong'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

test('failed sign-ins are recorded with the reason but never the password', function () {
    userWithRole(Role::Manager, ['username' => 'nimal']);

    $this->post(route('login'), ['username' => 'nimal', 'password' => 'wrong-secret']);
    $this->post(route('login'), ['username' => 'nobody', 'password' => 'x']);

    $this->assertDatabaseHas('activity_log', ['event' => 'login_failed', 'properties->reason' => 'wrong password', 'properties->username' => 'nimal']);
    $this->assertDatabaseHas('activity_log', ['event' => 'login_failed', 'properties->reason' => 'unknown username']);
    expect(Activity::where('properties', 'like', '%wrong-secret%')->exists())->toBeFalse();
});

test('inactive users cannot sign in', function () {
    userWithRole(Role::Manager, ['username' => 'nimal', 'is_active' => false]);

    $this->post(route('login'), ['username' => 'nimal', 'password' => 'password'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

test('a user deactivated while signed in is signed out on the next request', function () {
    $user = userWithRole(Role::SalesStaff);

    $this->actingAs($user);
    $user->update(['is_active' => false]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('self-registration is disabled', function () {
    $this->get('/register')->assertNotFound();
    expect(User::count())->toBe(0);
});
