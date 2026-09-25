<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\PriceList;
use App\Domain\Customers\Actions\SaveCustomerAction;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dummy farmers and regular customers for local development (until the owner sends the
 * real credit list). Safe to run again: customers whose phone already exists are skipped.
 */
class DevelopmentCustomerSeeder extends Seeder
{
    public function run(SaveCustomerAction $save): void
    {
        $owner = User::role(Role::SuperAdmin->value)->first();

        if ($owner === null) {
            return;
        }

        $wholesale = PriceList::query()->where('name', 'Wholesale')->value('id');
        $farmerCredit = PriceList::query()->where('name', 'like', 'Farmer%')->value('id');

        $customers = [
            ['name' => 'Sunil Perera', 'name_si' => 'සුනිල් පෙරේරා', 'phone' => '0771234567', 'nic' => '781234567V', 'area' => 'Thambuttegama', 'credit_limit' => 100000, 'credit_days' => 90, 'opening_balance' => 45000, 'price_list_id' => $farmerCredit],
            ['name' => 'Kamal Bandara', 'name_si' => 'කමල් බණ්ඩාර', 'phone' => '0712345678', 'nic' => '851112223V', 'area' => 'Eppawala', 'credit_limit' => 75000, 'credit_days' => 60, 'opening_balance' => 12500, 'price_list_id' => $farmerCredit],
            ['name' => 'Nimal Rathnayake', 'name_si' => 'නිමල් රත්නායක', 'phone' => '0763456789', 'nic' => '196912345678', 'area' => 'Talawa', 'credit_limit' => 50000, 'credit_days' => 30, 'opening_balance' => 0, 'price_list_id' => null],
            ['name' => 'Chandrika Kumari', 'name_si' => 'චන්ද්‍රිකා කුමාරි', 'phone' => '0784567890', 'nic' => '905556667V', 'area' => 'Galnewa', 'credit_limit' => 30000, 'credit_days' => 30, 'opening_balance' => 28000, 'price_list_id' => null],
            ['name' => 'Wijesinghe Agro Stores', 'name_si' => 'විජේසිංහ ඇග්‍රෝ ස්ටෝර්ස්', 'phone' => '0252264411', 'nic' => null, 'area' => 'Anuradhapura', 'credit_limit' => 250000, 'credit_days' => 45, 'opening_balance' => 86500, 'price_list_id' => $wholesale],
            ['name' => 'Priyantha Senarath', 'name_si' => 'ප්‍රියන්ත සෙනරත්', 'phone' => '0705678901', 'nic' => '821234987V', 'area' => 'Thambuttegama', 'credit_limit' => 0, 'credit_days' => 30, 'opening_balance' => 0, 'price_list_id' => null],
            ['name' => 'Ranjith Dissanayake', 'name_si' => 'රන්ජිත් දිසානායක', 'phone' => '0756789012', 'nic' => '197512309876', 'area' => 'Eppawala', 'credit_limit' => 60000, 'credit_days' => 120, 'opening_balance' => 5000, 'price_list_id' => $farmerCredit],
            ['name' => 'Somapala Herath', 'name_si' => 'සෝමපාල හේරත්', 'phone' => '0727890123', 'nic' => '601239874V', 'area' => 'Kekirawa', 'credit_limit' => 40000, 'credit_days' => 30, 'opening_balance' => 0, 'price_list_id' => null, 'is_active' => false],
        ];

        foreach ($customers as $data) {
            if (Customer::withTrashed()->where('phone', $data['phone'])->exists()) {
                continue;
            }

            $save->handle([
                'address' => null,
                'notes' => 'Dummy customer for testing.',
                'is_active' => true,
                ...$data,
            ], $owner);
        }
    }
}
