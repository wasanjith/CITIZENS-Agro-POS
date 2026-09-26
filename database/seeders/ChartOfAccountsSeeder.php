<?php

namespace Database\Seeders;

use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Services\ChartOfAccounts;
use Illuminate\Database\Seeder;

/**
 * System accounts (IMPLEMENTATION_PLAN section 14) and the usual expense heads of the
 * shop, each with its own 6xxx expense account.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public const EXPENSE_CATEGORIES = [
        'Rent',
        'Electricity',
        'Water',
        'Telephone & internet',
        'Transport & fuel',
        'Loading & unloading',
        'Repairs & maintenance',
        'Stationery & printing',
        'Tea & refreshments',
        'Licences & fees',
        'Miscellaneous',
    ];

    public function run(ChartOfAccounts $accounts): void
    {
        $accounts->ensureAll();

        foreach (self::EXPENSE_CATEGORIES as $name) {
            if (ExpenseCategory::query()->where('name', $name)->exists()) {
                continue;
            }

            ExpenseCategory::create(['name' => $name, 'account_id' => $accounts->createExpenseAccount($name)->id, 'is_active' => true]);
        }
    }
}
