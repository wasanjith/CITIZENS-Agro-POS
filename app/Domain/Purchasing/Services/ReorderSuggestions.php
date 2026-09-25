<?php

namespace App\Domain\Purchasing\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Inventory\Support\Qty;
use App\Domain\Purchasing\Models\Supplier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Purchase order lines for products at or below their reorder level.
 *
 * Products this supplier has delivered or been ordered from before come first; when
 * the supplier has no products yet, every low-stock product is suggested. Quantity =
 * reorder_qty, or reorder_level × 2 − on hand when no reorder quantity is set, in the
 * default purchase unit (rounded up to whole units where the unit has no decimals).
 * Products with variants are left out: the variant has to be chosen by hand.
 */
class ReorderSuggestions
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @return list<array{product_id: int, variant_id: null, unit_id: int, qty: string, last_cost: string|null}>
     */
    public function forSupplier(Supplier $supplier): array
    {
        $linked = $supplier->products()->pluck('supplier_products.last_cost', 'products.id');

        $products = Product::query()
            ->active()
            ->where('has_variants', false)
            ->where('reorder_level', '>', 0)
            ->when($linked->isNotEmpty(), fn (Builder $query) => $query->whereIn('id', $linked->keys()))
            ->with('units.unit')
            ->orderBy('short_code')
            ->get();

        $onHand = $this->stock->onHandByProduct($products->modelKeys());
        $lines = [];

        foreach ($products as $product) {
            $stock = Qty::of($onHand[$product->id] ?? '0');

            if ($stock->isGreaterThan($product->reorder_level)) {
                continue;
            }

            $needed = BigDecimal::of($product->reorder_qty)->isPositive()
                ? Qty::of($product->reorder_qty)
                : Qty::of($product->reorder_level)->multipliedBy(2)->minus($stock);

            if (! $needed->isPositive()) {
                continue;
            }

            /** @var ProductUnit|null $unit */
            $unit = $product->units->firstWhere('is_default_purchase', true) ?? $product->units->firstWhere('unit_id', $product->base_unit_id);

            if ($unit === null) {
                continue;
            }

            $qty = $needed->dividedBy($unit->factor, 3, RoundingMode::Up);

            if (! $unit->unit->allows_decimal) {
                $qty = $qty->toScale(0, RoundingMode::Up);
            }

            $lastCost = $linked->get($product->id);

            $lines[] = [
                'product_id' => $product->id,
                'variant_id' => null,
                'unit_id' => $unit->unit_id,
                'qty' => Qty::format($qty),
                'last_cost' => $lastCost !== null ? (string) BigDecimal::of($lastCost)->multipliedBy($unit->factor)->toScale(2, RoundingMode::HalfUp) : null,
            ];
        }

        return $lines;
    }
}
