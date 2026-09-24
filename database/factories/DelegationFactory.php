<?php

namespace Database\Factories;

use App\Domain\Identity\Models\Delegation;
use App\Domain\Identity\Support\PermissionCatalogue;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delegation>
 */
class DelegationFactory extends Factory
{
    protected $model = Delegation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_user_id' => User::factory(),
            'to_user_id' => User::factory(),
            'permissions' => PermissionCatalogue::delegable(),
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addHours(4),
            'reason' => 'Owner away',
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subHours(5),
            'expires_at' => now()->subHour(),
        ]);
    }
}
