<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StocktakeStatus;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\Stocktake;
use App\Domain\Inventory\Models\StocktakeLine;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts a stock count and freezes the system quantities.
 *
 * One line per batch that holds stock; products in scope without stock get one line
 * without a batch, so goods found on the shelf can still be counted. Products with
 * variants are counted per variant.
 */
class StartStocktakeAction
{
    public function __construct(private readonly DocumentNumber $numbers) {}

    /**
     * @param  list<int>  $categoryIds  empty = whole shop
     */
    public function handle(array $categoryIds, ?string $note, User $actor): Stocktake
    {
        return DB::transaction(function () use ($categoryIds, $note, $actor): Stocktake {
            $scopeIds = collect($categoryIds)
                ->flatMap(fn (int $id) => Category::find($id)?->descendantIdsAndSelf() ?? [])
                ->unique()
                ->values()
                ->all();

            $products = Product::query()
                ->when($categoryIds !== [], fn (Builder $query) => $query->whereIn('category_id', $scopeIds ?: [0]))
                ->where(fn (Builder $query) => $query->active()->orWhereIn('id', StockLevel::query()->where('qty_on_hand', '!=', 0)->select('product_id')))
                ->with(['variants' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('short_code')
                ->get();

            if ($products->isEmpty()) {
                throw ValidationException::withMessages(['categories' => 'There are no products to count in these categories.']);
            }

            $stocktake = Stocktake::create([
                'number' => $this->numbers->next('STK'),
                'scope' => $categoryIds !== [] ? array_map('intval', $categoryIds) : null,
                'status' => StocktakeStatus::Counting,
                'note' => $note,
                'started_by' => $actor->id,
            ]);

            $levels = StockLevel::query()
                ->whereIn('product_id', $products->modelKeys())
                ->where('qty_on_hand', '!=', 0)
                ->with('batch')
                ->get()
                ->groupBy(fn (StockLevel $level) => $level->product_id.'-'.($level->variant_id ?? 0));

            $rows = [];

            foreach ($products as $product) {
                $variantIds = $product->has_variants && $product->variants->isNotEmpty()
                    ? $product->variants->modelKeys()
                    : [null];

                // Stock left on variants that are no longer active still has to be counted.
                foreach ($levels->keys() as $key) {
                    [$productId, $variantId] = array_map('intval', explode('-', (string) $key));
                    if ($productId === $product->id && $variantId !== 0 && ! in_array($variantId, $variantIds, true)) {
                        $variantIds[] = $variantId;
                    }
                }

                foreach ($variantIds as $variantId) {
                    $batchLevels = $levels->get($product->id.'-'.($variantId ?? 0), collect());

                    if ($batchLevels->isEmpty()) {
                        $rows[] = $this->row($stocktake, $product->id, $variantId, null, '0', $product->reference_cost ?? '0');

                        continue;
                    }

                    foreach ($batchLevels->sortBy(fn (StockLevel $level) => [$level->batch->expiry_date->timestamp ?? PHP_INT_MAX, $level->batch_id]) as $level) {
                        $rows[] = $this->row($stocktake, $product->id, $variantId, $level->batch_id, $level->qty_on_hand, $level->batch->unit_cost);
                    }
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                StocktakeLine::query()->insert($chunk);
            }

            return $stocktake;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Stocktake $stocktake, int $productId, ?int $variantId, ?int $batchId, string $systemQty, string $unitCost): array
    {
        return [
            'stocktake_id' => $stocktake->id,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'batch_id' => $batchId,
            'system_qty' => $systemQty,
            'counted_qty' => null,
            'unit_cost' => $unitCost,
        ];
    }
}
