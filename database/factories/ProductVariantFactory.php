<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'short_code' => (string) fake()->unique()->numberBetween(100000, 999999),
            'sku' => null,
            'name' => ucfirst(fake()->word()),
            'attributes' => null,
            'is_active' => true,
        ];
    }
}
