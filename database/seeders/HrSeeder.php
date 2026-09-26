<?php

namespace Database\Seeders;

use App\Domain\HR\Models\LeaveType;
use App\Domain\HR\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * The shop's default working hours and the usual leave types (Shop & Office Employees
 * Act: 14 days annual, 7 days casual). All can be changed on the HR pages.
 */
class HrSeeder extends Seeder
{
    public function run(): void
    {
        if (! Shift::query()->exists()) {
            Shift::create([
                'name' => 'Shop hours',
                'start_time' => '08:00',
                'end_time' => '18:00',
                'grace_minutes' => 10,
                'working_days' => [1, 2, 3, 4, 5, 6],
                'is_default' => true,
            ]);
        }

        $types = [
            ['name' => 'Annual leave', 'days_per_year' => 14, 'is_paid' => true],
            ['name' => 'Casual leave', 'days_per_year' => 7, 'is_paid' => true],
            ['name' => 'No-pay leave', 'days_per_year' => 0, 'is_paid' => false],
        ];

        foreach ($types as $type) {
            LeaveType::firstOrCreate(['name' => $type['name']], $type + ['is_active' => true]);
        }
    }
}
