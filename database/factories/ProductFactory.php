<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'short_code' => (string) fake()->unique()->numberBetween(10000, 99999),
            'sku' => null,
            'name' => ucfirst(fake()->words(2, true)),
            'name_si' => null,
            'name_ta' => null,
            'aliases' => null,
            'description' => null,
            'category_id' => Category::factory(),
            'brand_id' => null,
            'base_unit_id' => fn () => Unit::query()->where('name', 'piece')->value('id') ?? Unit::factory()->named('piece'),
            'tax_id' => null,
            'has_variants' => false,
            'track_batches' => false,
            'track_expiry' => false,
            'reorder_level' => 0,
            'reorder_qty' => 0,
            'min_selling_margin_pct' => null,
            'reference_cost' => null,
            'attributes' => null,
            'is_active' => true,
        ];
    }

    /**
     * Add the base unit row (factor 1) that every product has.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Product $product): void {
            if (! $product->units()->where('unit_id', $product->base_unit_id)->exists()) {
                ProductUnit::create([
                    'product_id' => $product->id,
                    'unit_id' => $product->base_unit_id,
                    'factor' => 1,
                    'is_default_sale' => ! $product->units()->where('is_default_sale', true)->exists(),
                    'is_default_purchase' => ! $product->units()->where('is_default_purchase', true)->exists(),
                ]);
            }
        });
    }

    /**
     * Give the product a price in the base unit on the given (or default) price list.
     */
    public function priced(string $price, ?PriceList $priceList = null): static
    {
        return $this->afterCreating(function (Product $product) use ($price, $priceList): void {
            ProductPrice::create([
                'product_id' => $product->id,
                'unit_id' => $product->base_unit_id,
                'price_list_id' => ($priceList ?? PriceList::default() ?? PriceList::factory()->create(['is_default' => true]))->id,
                'price' => $price,
                'effective_from' => now()->subMinute(),
            ]);
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
