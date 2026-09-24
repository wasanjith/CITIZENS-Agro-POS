<?php

use App\Domain\Identity\Enums\Role;
use App\Models\User;

beforeEach(function () {
    $this->owner = userWithRole(Role::SuperAdmin, ['username' => 'owner']);
});

test('the Super Admin sees the user list', function () {
    userWithRole(Role::SalesStaff, ['name' => 'Kamal Perera']);

    $this->actingAs($this->owner)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Kamal Perera');
});

test('the Super Admin creates a counter staff account with a PIN', function () {
    $this->actingAs($this->owner)->post(route('admin.users.store'), [
        'name' => 'Nimal Silva',
        'username' => 'Nimal',
        'role' => Role::SalesStaff->value,
        'is_active' => '1',
        'password' => 'Secret-pass1',
        'password_confirmation' => 'Secret-pass1',
        'pin' => '2468',
        'pin_confirmation' => '2468',
    ])->assertRedirect(route('admin.users.index'));

    $user = User::where('username', 'nimal')->firstOrFail();

    expect($user->hasRole(Role::SalesStaff->value))->toBeTrue()
        ->and($user->checkPin('2468'))->toBeTrue()
        ->and($user->pin_hash)->not->toBe('2468');
});

test('changing a user role and resetting the password', function () {
    $user = userWithRole(Role::SalesStaff, ['username' => 'kamal']);

    $this->actingAs($this->owner)->put(route('admin.users.update', $user), [
        'name' => $user->name,
        'username' => 'kamal',
        'role' => Role::Manager->value,
        'is_active' => '1',
        'password' => 'New-pass-123',
        'password_confirmation' => 'New-pass-123',
    ])->assertRedirect(route('admin.users.index'));

    $user->refresh();
    expect($user->hasRole(Role::Manager->value))->toBeTrue()
        ->and($user->hasRole(Role::SalesStaff->value))->toBeFalse()
        ->and(Hash::check('New-pass-123', $user->password))->toBeTrue();
});

test('the last active Super Admin cannot be demoted or deactivated', function () {
    $other = userWithRole(Role::SuperAdmin, ['username' => 'second']);
    $this->owner->update(['is_active' => false]);

    $this->actingAs($other)->put(route('admin.users.update', $other), [
        'name' => $other->name, 'username' => 'second', 'role' => Role::SuperAdmin->value, 'is_active' => '1',
    ])->assertSessionHasNoErrors();

    $this->owner->update(['is_active' => true]);
    $this->actingAs($this->owner)->put(route('admin.users.update', $other), [
        'name' => $other->name, 'username' => 'second', 'role' => Role::Manager->value, 'is_active' => '1',
    ])->assertSessionHasNoErrors();

    // Only one Super Admin is left now: $this->owner, who may not demote themselves.
    $this->actingAs($this->owner)->put(route('admin.users.update', $this->owner), [
        'name' => $this->owner->name, 'username' => 'owner', 'role' => Role::Manager->value, 'is_active' => '1',
    ])->assertSessionHasErrors('role');

    expect($this->owner->fresh()->isSuperAdmin())->toBeTrue();
});

test('usernames must be unique and PINs must be 4 to 6 digits', function () {
    userWithRole(Role::SalesStaff, ['username' => 'taken']);

    $this->actingAs($this->owner)->post(route('admin.users.store'), [
        'name' => 'X',
        'username' => 'taken',
        'role' => Role::SalesStaff->value,
        'password' => 'Secret-pass1',
        'password_confirmation' => 'Secret-pass1',
        'pin' => '12',
        'pin_confirmation' => '12',
    ])->assertSessionHasErrors(['username', 'pin']);
});

test('managers and counter staff cannot manage users', function (Role $role) {
    $this->actingAs(userWithRole($role))
        ->get(route('admin.users.index'))
        ->assertForbidden();
})->with([Role::Manager, Role::SalesStaff]);
