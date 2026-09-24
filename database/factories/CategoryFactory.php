<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => ucfirst(fake()->unique()->word()).' '.fake()->numberBetween(1, 999),
            'name_si' => null,
            'code_from' => null,
            'code_to' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function range(int $from, int $to): static
    {
        return $this->state(fn (array $attributes) => ['code_from' => $from, 'code_to' => $to]);
    }

    public function childOf(Category $parent): static
    {
        return $this->state(fn (array $attributes) => ['parent_id' => $parent->id]);
    }
}
