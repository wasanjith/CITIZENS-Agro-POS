<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function withPin(string $pin = '1234'): static
    {
        return $this->state(fn (array $attributes) => ['pin_hash' => Hash::make($pin)]);
    }

    public function withRole(Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role->value));
    }

    public function superAdmin(): static
    {
        return $this->withRole(Role::SuperAdmin);
    }

    public function manager(): static
    {
        return $this->withRole(Role::Manager);
    }

    public function salesStaff(): static
    {
        return $this->withRole(Role::SalesStaff);
    }
}
