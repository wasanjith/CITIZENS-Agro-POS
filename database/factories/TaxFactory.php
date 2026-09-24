<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tax>
 */
class TaxFactory extends Factory
{
    protected $model = Tax::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'VAT',
            'rate' => '18.00',
            'is_active' => true,
        ];
    }
}
