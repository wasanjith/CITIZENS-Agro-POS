<?php

namespace Database\Factories;

use App\Domain\HR\Enums\EmploymentType;
use App\Domain\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'E-'.fake()->unique()->numerify('9##'),
            'full_name' => fake()->name(),
            'designation' => 'Sales assistant',
            'join_date' => today()->subYear()->startOfMonth(),
            'employment_type' => EmploymentType::Permanent,
            'basic_salary' => '30000',
            'is_epf_member' => true,
            'is_active' => true,
        ];
    }

    public function withoutEpf(): static
    {
        return $this->state(fn (array $attributes) => ['is_epf_member' => false]);
    }
}
