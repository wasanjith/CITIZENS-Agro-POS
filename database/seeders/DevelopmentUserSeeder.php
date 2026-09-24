<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo accounts for local development only. Never run in production;
 * use `php artisan pos:create-owner` there instead.
 */
class DevelopmentUserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['username' => 'owner', 'name' => 'Shop Owner', 'role' => Role::SuperAdmin, 'pin' => '1111'],
            ['username' => 'manager', 'name' => 'Shop Manager', 'role' => Role::Manager, 'pin' => '2222'],
            ['username' => 'staff1', 'name' => 'Counter Staff 1', 'role' => Role::SalesStaff, 'pin' => '3331'],
            ['username' => 'staff2', 'name' => 'Counter Staff 2', 'role' => Role::SalesStaff, 'pin' => '3332'],
            ['username' => 'staff3', 'name' => 'Counter Staff 3', 'role' => Role::SalesStaff, 'pin' => '3333'],
        ];

        foreach ($users as $definition) {
            $user = User::firstOrCreate(
                ['username' => $definition['username']],
                [
                    'name' => $definition['name'],
                    'password' => 'password',
                    'is_active' => true,
                ],
            );

            $user->forceFill(['pin_hash' => Hash::make($definition['pin'])])->save();
            $user->syncRoles([$definition['role']->value]);
        }

        $this->command->table(
            ['Username', 'Password', 'PIN', 'Role'],
            array_map(fn (array $user) => [$user['username'], 'password', $user['pin'], $user['role']->label()], $users),
        );
    }
}
