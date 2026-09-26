<?php

namespace Database\Seeders;

use App\Domain\HR\Actions\SaveEmployeeAction;
use App\Domain\HR\Enums\EmploymentType;
use App\Domain\HR\Enums\SalaryComponentCalc;
use App\Domain\HR\Enums\SalaryComponentType;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\SalaryComponent;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo employees for the demo logins (local only): the manager and the three counter
 * staff, with a transport allowance and the welfare society deduction.
 */
class DevelopmentHrSeeder extends Seeder
{
    public function run(SaveEmployeeAction $save): void
    {
        $transport = SalaryComponent::firstOrCreate(['name' => 'Transport allowance'], [
            'type' => SalaryComponentType::Allowance, 'calc' => SalaryComponentCalc::Fixed, 'value' => '3000', 'is_epf_applicable' => false, 'is_active' => true,
        ]);
        $attendance = SalaryComponent::firstOrCreate(['name' => 'Attendance allowance'], [
            'type' => SalaryComponentType::Allowance, 'calc' => SalaryComponentCalc::Fixed, 'value' => '2000', 'is_epf_applicable' => true, 'is_active' => true,
        ]);
        $welfare = SalaryComponent::firstOrCreate(['name' => 'Welfare society'], [
            'type' => SalaryComponentType::Deduction, 'calc' => SalaryComponentCalc::Fixed, 'value' => '200', 'is_epf_applicable' => false, 'is_active' => true,
        ]);

        $people = [
            ['username' => 'manager', 'full_name' => 'Sunil Perera', 'name_si' => 'සුනිල් පෙරේරා', 'designation' => 'Shop manager', 'basic_salary' => '65000', 'epf_no' => '101'],
            ['username' => 'staff1', 'full_name' => 'Nimal Bandara', 'name_si' => 'නිමල් බණ්ඩාර', 'designation' => 'Sales assistant', 'basic_salary' => '42000', 'epf_no' => '102'],
            ['username' => 'staff2', 'full_name' => 'Kamala Herath', 'name_si' => 'කමලා හේරත්', 'designation' => 'Sales assistant', 'basic_salary' => '40000', 'epf_no' => '103'],
            ['username' => 'staff3', 'full_name' => 'Ruwan Dissanayake', 'name_si' => 'රුවන් දිසානායක', 'designation' => 'Sales assistant', 'basic_salary' => '38000', 'epf_no' => null],
        ];

        foreach ($people as $person) {
            $user = User::query()->where('username', $person['username'])->first();

            if ($user === null || Employee::query()->where('user_id', $user->id)->exists()) {
                continue;
            }

            $save->handle([
                ...$person,
                'user_id' => $user->id,
                'join_date' => today()->subYears(2)->startOfMonth()->toDateString(),
                'employment_type' => EmploymentType::Permanent->value,
                'is_epf_member' => $person['epf_no'] !== null,
                'is_active' => true,
                'components' => [
                    $transport->id => ['assigned' => true],
                    $attendance->id => ['assigned' => true],
                    $welfare->id => ['assigned' => true],
                ],
            ]);
        }
    }
}
