<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\StockLevel;
use App\Http\Controllers\Controller;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/inventory/batches?product_id=&variant_id=
 * Batches of one product/variant that hold stock (first expiry first), for the
 * adjustment and supplier-return line editors. Costs only for catalog.cost.view.
 */
class BatchLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
        ]);

        $variantId = $validated['variant_id'] ?? null;
        $showCost = $request->user()->can('viewCost', Product::class);

        $levels = StockLevel::query()
            ->with('batch')
            ->join('batches', 'batches.id', '=', 'stock_levels.batch_id')
            ->where('stock_levels.product_id', $validated['product_id'])
            ->when($variantId === null, fn (Builder $query) => $query->whereNull('stock_levels.variant_id'), fn (Builder $query) => $query->where('stock_levels.variant_id', $variantId))
            ->where('stock_levels.qty_on_hand', '!=', 0)
            ->orderByRaw('batches.expiry_date IS NULL, batches.expiry_date, batches.received_at')
            ->select('stock_levels.*')
            ->get();

        return response()->json([
            'batches' => $levels->map(fn (StockLevel $level) => array_filter([
                'id' => $level->batch_id,
                'label' => $level->batch->label(),
                'lot_no' => $level->batch->lot_no,
                'expiry_date' => $level->batch->expiry_date?->toDateString(),
                'is_default' => $level->batch->isDefault(),
                'on_hand' => $level->qty_on_hand,
                'available' => (string) BigDecimal::of($level->qty_on_hand)->minus($level->qty_reserved),
                'unit_cost' => $showCost ? $level->batch->unit_cost : null,
            ], fn ($value) => $value !== null))->values(),
        ]);
    }
}
