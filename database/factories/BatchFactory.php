<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\Batch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A batch without stock. Stock itself is only ever added through StockService.
 *
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    protected $model = Batch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'variant_id' => null,
            'lot_no' => strtoupper(fake()->bothify('L###??')),
            'mfg_date' => null,
            'expiry_date' => null,
            'unit_cost' => '100.0000',
            'received_at' => now(),
        ];
    }
}
