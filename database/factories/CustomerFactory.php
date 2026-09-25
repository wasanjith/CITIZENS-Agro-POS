<?php

namespace Database\Factories;

use App\Domain\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'C-'.fake()->unique()->numerify('9####'),
            'name' => fake()->name(),
            'name_si' => null,
            'phone' => '07'.fake()->unique()->numerify('########'),
            'nic' => null,
            'address' => fake()->streetAddress(),
            'area' => fake()->randomElement(['Thambuttegama', 'Eppawala', 'Talawa', 'Galnewa']),
            'price_list_id' => null,
            'credit_limit' => 0,
            'credit_days' => 30,
            'is_active' => true,
        ];
    }

    public function withCredit(string $limit = '50000', int $days = 30): static
    {
        return $this->state(fn (array $attributes) => ['credit_limit' => $limit, 'credit_days' => $days]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
