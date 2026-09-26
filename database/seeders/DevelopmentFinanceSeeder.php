<?php

namespace Database\Seeders;

use App\Domain\Finance\Actions\SaveBankAccountAction;
use App\Domain\Finance\Enums\BankAccountType;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Two demo bank accounts (local only). The owner moves card and transfer money into
 * the bank by hand (Move money).
 */
class DevelopmentFinanceSeeder extends Seeder
{
    public function run(SaveBankAccountAction $save): void
    {
        $owner = User::role(Role::SuperAdmin->value)->first();

        if ($owner === null || BankAccount::query()->exists()) {
            return;
        }

        $save->handle([
            'bank_name' => 'Bank of Ceylon',
            'branch' => 'Kurunegala',
            'account_no' => '0081234567',
            'account_name' => 'Citizens Agro',
            'type' => BankAccountType::Current,
            'opening_balance' => '250000.00',
            'opening_date' => today()->startOfMonth(),
            'receives_card_payments' => false,
            'is_active' => true,
        ], $owner);

        $save->handle([
            'bank_name' => 'Peoples Bank',
            'branch' => 'Kurunegala',
            'account_no' => '1122334455',
            'account_name' => 'Citizens Agro',
            'type' => BankAccountType::Savings,
            'opening_balance' => '100000.00',
            'opening_date' => today()->startOfMonth(),
            'receives_card_payments' => false,
            'is_active' => true,
        ], $owner);
    }
}
