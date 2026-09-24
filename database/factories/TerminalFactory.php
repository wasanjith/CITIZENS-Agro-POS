<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\TerminalType;
use App\Domain\Identity\Models\Terminal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Terminal>
 */
class TerminalFactory extends Factory
{
    protected $model = Terminal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 9);

        return [
            'name' => "Counter {$number}",
            'code' => "C{$number}",
            'type' => TerminalType::Counter,
            'counter_no' => $number,
            'is_active' => true,
        ];
    }

    public function mainCashier(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Main Cashier',
            'code' => 'MAIN',
            'type' => TerminalType::MainCashier,
            'counter_no' => null,
        ]);
    }

    public function counter(int $number): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => "Counter {$number}",
            'code' => "C{$number}",
            'type' => TerminalType::Counter,
            'counter_no' => $number,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
