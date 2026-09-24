<?php

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Enums\Role;

/**
 * Read access to the permission catalogue defined in config/pos.php.
 */
class PermissionCatalogue
{
    /**
     * @return array<string, array{roles: list<string>, delegable: bool|null}>
     */
    public static function all(): array
    {
        return config('pos.permissions', []);
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return list<string>
     */
    public static function forRole(Role $role): array
    {
        return array_keys(array_filter(
            self::all(),
            fn (array $definition): bool => in_array($role->value, $definition['roles'], true),
        ));
    }

    /**
     * Permissions included in the default Cashier Handover scope.
     *
     * @return list<string>
     */
    public static function delegable(): array
    {
        return array_keys(array_filter(
            self::all(),
            fn (array $definition): bool => $definition['delegable'] === true,
        ));
    }

    /**
     * Permissions that can never be delegated.
     *
     * @return list<string>
     */
    public static function nonDelegable(): array
    {
        return array_keys(array_filter(
            self::all(),
            fn (array $definition): bool => $definition['delegable'] === false,
        ));
    }

    public static function isDelegable(string $permission): bool
    {
        return (self::all()[$permission]['delegable'] ?? null) === true;
    }
}
