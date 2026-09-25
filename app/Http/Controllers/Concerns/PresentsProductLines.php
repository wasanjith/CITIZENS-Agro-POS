<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Inventory\Services\StockService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the rows the Alpine line editors (purchase order, GRN, adjustment …) start
 * with: product code/name, its units, live stock and reorder level, plus the line's
 * own fields. Used for existing documents and for re-showing a form after a
 * validation error.
 */
trait PresentsProductLines
{
    /**
     * @param  list<array<string, mixed>>  $lines  each with product_id and variant_id plus any line fields
     * @return list<array<string, mixed>>
     */
    protected function presentLines(array $lines, bool $withCost = false): array
    {
        $productIds = array_values(array_unique(array_map(fn (array $line) => (int) ($line['product_id'] ?? 0), $lines)));

        /** @var Collection<int, Product> $products */
        $products = Product::withTrashed()
            ->with(['units.unit', 'baseUnit', 'variants' => fn ($query) => $query->withTrashed()])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $stock = app(StockService::class)->totals($productIds);
        $rows = [];

        foreach ($lines as $line) {
            $product = $products->get((int) ($line['product_id'] ?? 0));

            if ($product === null) {
                continue;
            }

            $variantId = isset($line['variant_id']) && $line['variant_id'] !== '' ? (int) $line['variant_id'] : null;
            $rows[] = [...$this->productInfo($product, $variantId, $stock, $withCost), ...$line];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<int, array{on_hand: string, reserved: string, available: string}>>  $stock
     * @return array<string, mixed>
     */
    protected function productInfo(Product $product, ?int $variantId, array $stock, bool $withCost = false): array
    {
        $variant = $variantId !== null ? $product->variants->firstWhere('id', $variantId) : null;

        $info = [
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'short_code' => $variant->short_code ?? $product->short_code,
            'name' => trim($product->name.' '.($variant->name ?? '')),
            'name_si' => $product->name_si,
            'base_unit' => $product->baseUnit?->symbol,
            'stock' => $stock[$product->id][$variantId ?? 0]['available'] ?? '0.000',
            'reorder_level' => $product->reorder_level,
            'track_batches' => $product->track_batches,
            'track_expiry' => $product->track_expiry,
            'units' => $product->units->map(fn (ProductUnit $unit) => [
                'id' => $unit->unit_id,
                'name' => $unit->unit->name,
                'symbol' => $unit->unit->symbol,
                'factor' => $unit->factor,
                'allows_decimal' => $unit->unit->allows_decimal,
                'is_default_purchase' => $unit->is_default_purchase,
            ])->values()->all(),
        ];

        if ($withCost) {
            $info['reference_cost'] = $product->reference_cost;
        }

        return $info;
    }
}
