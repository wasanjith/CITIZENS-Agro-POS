<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\Role as RoleName;
use App\Domain\Identity\Support\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syncs roles and permissions with the catalogue in config/pos.php.
 * Safe to run again after the catalogue changes.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalogue::names() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Permission::query()->whereNotIn('name', PermissionCatalogue::names())->delete();

        foreach (RoleName::cases() as $roleName) {
            Role::findOrCreate($roleName->value, 'web')
                ->syncPermissions(PermissionCatalogue::forRole($roleName));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
