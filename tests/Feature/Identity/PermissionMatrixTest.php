<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Support\PermissionCatalogue;

/*
 * The role permissions must match the catalogue in config/pos.php
 * (docs/IMPLEMENTATION_PLAN.md section 4).
 */

test('each role holds exactly the permissions of the catalogue', function (Role $role) {
    $user = userWithRole($role);
    $expected = PermissionCatalogue::forRole($role);

    foreach (PermissionCatalogue::names() as $permission) {
        expect($user->can($permission))->toBe(
            $role === Role::SuperAdmin || in_array($permission, $expected, true),
            "{$role->value} → {$permission}",
        );
    }
})->with(Role::cases());

test('key business rules of the permission matrix', function () {
    $staff = userWithRole(Role::SalesStaff);
    $manager = userWithRole(Role::Manager);

    // Counter staff bill, reprint and create purchase orders, but never settle, see cost or approve.
    expect($staff->can('pos.sell'))->toBeTrue()
        ->and($staff->can('pos.reprint'))->toBeTrue()
        ->and($staff->can('purchasing.po.create'))->toBeTrue()
        ->and($staff->can('pos.settle'))->toBeFalse()
        ->and($staff->can('catalog.cost.view'))->toBeFalse()
        ->and($staff->can('purchasing.po.approve'))->toBeFalse();

    // The Manager runs inventory but only settles or sees Live Billing through a handover.
    expect($manager->can('catalog.manage'))->toBeTrue()
        ->and($manager->can('purchasing.po.approve'))->toBeTrue()
        ->and($manager->can('pos.settle'))->toBeFalse()
        ->and($manager->can('pos.live_view'))->toBeFalse()
        ->and($manager->can('finance.banks.manage'))->toBeFalse();
});

test('the Super Admin passes every check, including unknown abilities', function () {
    $owner = userWithRole(Role::SuperAdmin);

    expect($owner->can('pos.settle'))->toBeTrue()
        ->and($owner->can('some.future.permission'))->toBeTrue();
});

test('settlement, live view and money handling are delegable; finance, payroll and admin are not', function () {
    expect(PermissionCatalogue::delegable())->toContain('pos.settle', 'pos.live_view', 'pos.void', 'drawer.manage')
        ->and(PermissionCatalogue::nonDelegable())->toContain(
            'finance.banks.manage',
            'hr.payroll.manage',
            'admin.users.manage',
            'reports.profit',
        );
});
