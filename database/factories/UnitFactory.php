<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'unit'.fake()->unique()->numberBetween(1, 99999);

        return [
            'name' => $name,
            'name_si' => null,
            'symbol' => mb_substr($name, 0, 10),
            'allows_decimal' => false,
        ];
    }

    public function named(string $name, bool $allowsDecimal = false): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $name,
            'symbol' => $name,
            'allows_decimal' => $allowsDecimal,
        ]);
    }
}
