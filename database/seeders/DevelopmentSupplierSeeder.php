<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Purchasing\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * The shop's seed suppliers, for local development (never run in production). Each one is
 * linked to the products of the brand it supplies (supplier_products), so a new purchase
 * order suggests only that supplier's products. Run after DevelopmentCatalogSeeder.
 */
class DevelopmentSupplierSeeder extends Seeder
{
    public function run(FinancePosting $finance): void
    {
        $suppliers = [
            // name => details, brands supplied
            'Bathalagoda Agro' => [[
                'contact_person' => 'Bathala Mama',
                'phone' => '0776793706',
                'email' => 'wasakodi79@gmail.com',
                'address' => 'No: 164, New Housing Scheme, Jayawardhanapura',
                'opening_balance' => '150000.00',
            ], ['Bathalagoda Agro']],
            'CIC' => [[
                'contact_person' => 'Mr. Harshana',
                'phone' => '0450547700',
                'email' => 'wasanjith@crow.lk',
                'address' => 'Grama 03, Muwangala roaad, Ampara',
                'opening_balance' => '120000.00',
            ], ['CIC']],
            'Ishara Lak Seeds' => [[], ['Ishara Lak Seeds']],
        ];

        foreach ($suppliers as $name => [$details, $brands]) {
            $supplier = Supplier::updateOrCreate(['name' => $name], [
                'payment_terms_days' => 30,
                'opening_balance' => '0.00',
                'is_active' => true,
                ...$details,
            ]);

            if ((float) $supplier->opening_balance > 0) {
                $finance->supplierOpening($supplier);
            }

            $productIds = Product::query()->whereHas('brand', fn ($query) => $query->whereIn('name', $brands))->pluck('id');
            $costs = Product::query()->whereKey($productIds)->pluck('reference_cost', 'id');

            $supplier->products()->syncWithoutDetaching(
                $productIds->mapWithKeys(fn (int $id) => [$id => ['last_cost' => $costs[$id]]])->all(),
            );
        }
    }
}
