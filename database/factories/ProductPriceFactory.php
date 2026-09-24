<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPrice>
 */
class ProductPriceFactory extends Factory
{
    protected $model = ProductPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'variant_id' => null,
            'unit_id' => Unit::factory(),
            'price_list_id' => PriceList::factory(),
            'price' => fake()->randomFloat(2, 50, 5000),
            'effective_from' => now()->subMinute(),
        ];
    }
}
