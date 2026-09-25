<?php

namespace App\Domain\Sales\Jobs;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Support\Qty;
use App\Domain\Sales\Enums\SaleStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Nightly: products.sales_velocity_30d = base units sold in the last 30 days (settled
 * sales). Search ranks fast sellers first, so changed products are reindexed.
 */
class RecalculateSalesVelocityJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $sold = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sales.status', [SaleStatus::Settled->value, SaleStatus::PartiallyReturned->value])
            ->where('sales.settled_at', '>=', now()->subDays(30))
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id, SUM(sale_items.base_qty) AS qty')
            ->pluck('qty', 'product_id');

        $changed = [];

        Product::query()
            ->select(['id', 'sales_velocity_30d'])
            ->where(fn ($query) => $query->where('sales_velocity_30d', '>', 0)->orWhereIn('id', $sold->keys()))
            ->chunkById(500, function ($products) use ($sold, &$changed): void {
                foreach ($products as $product) {
                    $velocity = (string) Qty::of((string) ($sold[$product->id] ?? '0'));

                    if ($velocity !== $product->sales_velocity_30d) {
                        DB::table('products')->where('id', $product->id)->update(['sales_velocity_30d' => $velocity]);
                        $changed[] = $product->id;
                    }
                }
            });

        foreach (Product::query()->whereIn('id', $changed)->get() as $product) {
            $product->searchable();
        }
    }
}
