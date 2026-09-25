<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\StockLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Queries behind the low-stock list, the expiry list and the daily alert.
 */
class StockAlerts
{
    /**
     * Active products with a reorder level whose total stock is at or below it.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function whereLowStock(Builder $query): Builder
    {
        return $query
            ->where('products.is_active', true)
            ->where('products.reorder_level', '>', 0)
            ->whereRaw('COALESCE((SELECT SUM(sl.qty_on_hand) FROM stock_levels sl WHERE sl.product_id = products.id), 0) <= products.reorder_level');
    }

    public function lowStockCount(): int
    {
        return $this->whereLowStock(Product::query())->count();
    }

    /**
     * Batches with stock that expire within $days (expired ones included).
     *
     * @return Builder<StockLevel>
     */
    public function expiringLevels(int $days): Builder
    {
        return StockLevel::query()
            ->join('batches', 'batches.id', '=', 'stock_levels.batch_id')
            ->where('stock_levels.qty_on_hand', '>', 0)
            ->whereNotNull('batches.expiry_date')
            ->where('batches.expiry_date', '<=', now()->addDays($days)->toDateString())
            ->select('stock_levels.*');
    }

    public function expiringCount(int $days): int
    {
        return $this->expiringLevels($days)->count();
    }

    /**
     * SUM(qty_on_hand) per product as a sub-select, for list pages.
     */
    public static function onHandSubquery(): QueryBuilder
    {
        return DB::table('stock_levels')
            ->selectRaw('COALESCE(SUM(qty_on_hand), 0)')
            ->whereColumn('stock_levels.product_id', 'products.id');
    }
}
