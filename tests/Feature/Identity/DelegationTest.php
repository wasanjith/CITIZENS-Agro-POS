<?php

use App\Domain\Identity\Actions\CreateDelegationAction;
use App\Domain\Identity\Actions\RevokeDelegationAction;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Exceptions\NonDelegablePermissionException;
use App\Domain\Identity\Services\CashierAuthority;

beforeEach(function () {
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->manager = userWithRole(Role::Manager);
});

test('a handover gives the manager cashier permissions until it expires', function () {
    app(CreateDelegationAction::class)->handle($this->owner, $this->manager, now()->addHours(3));

    expect($this->manager->can('pos.settle'))->toBeTrue()
        ->and($this->manager->can('pos.live_view'))->toBeTrue()
        ->and($this->manager->can('drawer.manage'))->toBeTrue()
        ->and(app(CashierAuthority::class)->holder()->is($this->manager))->toBeTrue();

    $this->travel(4)->hours();

    expect($this->manager->can('pos.settle'))->toBeFalse()
        ->and(app(CashierAuthority::class)->holder()->is($this->owner))->toBeTrue();
});

test('a handover never grants non-delegable permissions', function () {
    app(CreateDelegationAction::class)->handle($this->owner, $this->manager, now()->addHours(3));

    expect($this->manager->can('finance.banks.manage'))->toBeFalse()
        ->and($this->manager->can('hr.payroll.manage'))->toBeFalse()
        ->and($this->manager->can('admin.users.manage'))->toBeFalse();
});

test('asking to delegate a non-delegable permission is rejected', function () {
    app(CreateDelegationAction::class)->handle(
        $this->owner,
        $this->manager,
        now()->addHours(3),
        ['pos.settle', 'finance.banks.manage'],
    );
})->throws(NonDelegablePermissionException::class, 'finance.banks.manage');

test('permissions outside the handover scope are rejected too', function () {
    app(CreateDelegationAction::class)->handle($this->owner, $this->manager, now()->addHour(), ['catalog.manage']);
})->throws(NonDelegablePermissionException::class);

test('the owner can revoke a delegation at any time', function () {
    $delegation = app(CreateDelegationAction::class)->handle($this->owner, $this->manager, now()->addHours(3));
    expect($this->manager->can('pos.settle'))->toBeTrue();

    app(RevokeDelegationAction::class)->handle($delegation, $this->owner);

    expect($this->manager->can('pos.settle'))->toBeFalse()
        ->and($delegation->fresh()->revoked_by)->toBe($this->owner->id);
});

test('a delegation must expire in the future and cannot target oneself or an inactive user', function (string $case) {
    [$to, $expiresAt] = match ($case) {
        'expired' => [$this->manager, now()->subMinute()],
        'self' => [$this->owner, now()->addHour()],
        'inactive' => [userWithRole(Role::Manager, ['is_active' => false]), now()->addHour()],
    };

    app(CreateDelegationAction::class)->handle($this->owner, $to, $expiresAt);
})->with(['expired', 'self', 'inactive'])->throws(DomainException::class);

test('every delegation change is written to the audit log', function () {
    $delegation = app(CreateDelegationAction::class)->handle($this->owner, $this->manager, now()->addHours(3), reason: 'Bank visit');
    app(RevokeDelegationAction::class)->handle($delegation, $this->owner);

    $this->assertDatabaseHas('activity_log', ['subject_type' => $delegation->getMorphClass(), 'subject_id' => $delegation->id, 'event' => 'created']);
    $this->assertDatabaseHas('activity_log', ['subject_type' => $delegation->getMorphClass(), 'subject_id' => $delegation->id, 'event' => 'updated']);
});
