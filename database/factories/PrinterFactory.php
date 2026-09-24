<?php

namespace Database\Factories;

use App\Domain\Identity\Models\Printer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Printer>
 */
class PrinterFactory extends Factory
{
    protected $model = Printer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Printer '.fake()->unique()->numberBetween(1, 99),
            'terminal_id' => null,
            'windows_name' => 'POS-80',
            'model' => 'Generic 80mm thermal',
            'paper_width_mm' => 80,
            'dpi' => 203,
            'has_cash_drawer' => false,
            'is_active' => true,
        ];
    }
}
