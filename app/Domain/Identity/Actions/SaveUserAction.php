<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a user together with their role, password and PIN.
 */
class SaveUserAction
{
    /**
     * @param  array{name: string, username: string, email?: string|null, role: string, is_active?: bool, password?: string|null, pin?: string|null}  $data
     */
    public function handle(array $data, ?User $user = null, ?User $actingUser = null): User
    {
        $user ??= new User;
        $role = Role::from($data['role']);
        $isActive = (bool) ($data['is_active'] ?? true);

        if ($user->exists) {
            $this->guardAgainstLockingOut($user, $role, $isActive, $actingUser);
        }

        return DB::transaction(function () use ($user, $data, $role, $isActive): User {
            $user->fill([
                'name' => $data['name'],
                'username' => mb_strtolower($data['username']),
                'email' => $data['email'] ?? null,
                'is_active' => $isActive,
            ]);

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            $user->save();
            $user->syncRoles([$role->value]);

            if (! empty($data['pin'])) {
                $user->setPin($data['pin']);
            }

            return $user;
        });
    }

    /**
     * The shop must always keep at least one active Super Admin, and nobody may
     * deactivate or demote themselves.
     */
    private function guardAgainstLockingOut(User $user, Role $role, bool $isActive, ?User $actingUser): void
    {
        if ($actingUser?->is($user) && (! $isActive || $role !== $user->primaryRole())) {
            throw ValidationException::withMessages([
                'role' => 'You cannot change your own role or deactivate your own account.',
            ]);
        }

        $losesSuperAdmin = $user->isSuperAdmin() && ($role !== Role::SuperAdmin || ! $isActive);

        if (! $losesSuperAdmin) {
            return;
        }

        $otherActiveSuperAdmins = User::query()
            ->active()
            ->role(Role::SuperAdmin->value)
            ->whereKeyNot($user->id)
            ->count();

        if ($otherActiveSuperAdmins === 0) {
            throw ValidationException::withMessages([
                'role' => 'At least one active Super Admin is required.',
            ]);
        }
    }
}
