<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each brand comes from one supplier (a supplier may have several brands).
     * Existing data: a brand named like a supplier gets that supplier, every supplier without
     * such a brand gets one, and each brand's products are linked to its supplier.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });

        $now = now();
        $suppliers = DB::table('suppliers')->whereNull('deleted_at')->orderBy('id')->get(['id', 'name']);

        foreach ($suppliers as $supplier) {
            $brand = DB::table('brands')->whereNull('deleted_at')->where('name', $supplier->name)->first(['id', 'supplier_id']);

            if ($brand === null) {
                DB::table('brands')->insert(['name' => $supplier->name, 'supplier_id' => $supplier->id, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            } elseif ($brand->supplier_id === null) {
                DB::table('brands')->where('id', $brand->id)->update(['supplier_id' => $supplier->id]);
            }
        }

        $links = DB::table('products')
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->whereNotNull('brands.supplier_id')
            ->whereNull('products.deleted_at')
            ->get(['products.id as product_id', 'brands.supplier_id', 'products.reference_cost']);

        foreach ($links as $link) {
            DB::table('supplier_products')->insertOrIgnore([
                'supplier_id' => $link->supplier_id,
                'product_id' => $link->product_id,
                'last_cost' => $link->reference_cost,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
