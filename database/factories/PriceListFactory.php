<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\PriceList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceList>
 */
class PriceListFactory extends Factory
{
    protected $model = PriceList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'List '.fake()->unique()->numberBetween(1, 9999),
            'is_default' => false,
        ];
    }
}
