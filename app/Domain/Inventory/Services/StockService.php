<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Data\StockAllocation;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Support\Qty;
use App\Domain\System\Services\Settings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only place that changes stock.
 *
 * Every change writes an append-only stock_movements row and updates the cached
 * stock_levels row of the same batch in one transaction, with the level rows
 * locked FOR UPDATE. Quantities are always in the product's base unit.
 *
 * Products without batch tracking keep their stock in one default batch per
 * product/variant (moving-average cost), so every code path is the same.
 */
class StockService
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Add stock (GRN, opening stock, sale return).
     *
     * Batch-tracked products get a new batch per receipt; others go to their default batch.
     *
     * @param  array{lot_no?: string|null, mfg_date?: string|null, expiry_date?: string|null, grn_line_id?: int|null}  $batchData
     * @param  string  $unitCost  cost per base unit
     */
    public function receive(
        Product $product,
        ?int $variantId,
        string $baseQty,
        string $unitCost,
        array $batchData = [],
        ?Model $reference = null,
        MovementType $type = MovementType::Grn,
        ?int $userId = null,
        ?string $note = null,
    ): Batch {
        $qty = $this->positive($baseQty);
        $cost = BigDecimal::of($unitCost)->toScale(4, RoundingMode::HalfUp);

        return DB::transaction(function () use ($product, $variantId, $qty, $cost, $batchData, $reference, $type, $userId, $note): Batch {
            if ($this->usesBatches($product, $batchData)) {
                $batch = Batch::create([
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'lot_no' => $batchData['lot_no'] ?? null,
                    'mfg_date' => $batchData['mfg_date'] ?? null,
                    'expiry_date' => $batchData['expiry_date'] ?? null,
                    'unit_cost' => (string) $cost,
                    'received_at' => now(),
                    'grn_line_id' => $batchData['grn_line_id'] ?? null,
                ]);
                $level = $this->levelForUpdate($batch);
            } else {
                $batch = $this->defaultBatch($product->id, $variantId);
                $level = $this->levelForUpdate($batch);
                $batch->unit_cost = (string) $this->movingAverage($level, $batch, $qty, $cost);
                $batch->save();
            }

            $this->move($level, $batch, $qty, $cost, $type, $reference, $userId, $note);

            return $batch;
        });
    }

    /**
     * Take stock out, first-expiry-first-out across batches (or from one given batch).
     * Reserved stock is not available. Returns what was taken from which batch, with
     * the batch cost, for COGS.
     *
     * @return list<StockAllocation>
     *
     * @throws InsufficientStockException
     */
    public function issue(
        Product $product,
        ?int $variantId,
        string $baseQty,
        MovementType $type,
        ?Model $reference = null,
        ?int $userId = null,
        ?Batch $fromBatch = null,
        ?string $note = null,
    ): array {
        $qty = $this->positive($baseQty);

        return DB::transaction(function () use ($product, $variantId, $qty, $type, $reference, $userId, $fromBatch, $note): array {
            $plan = $this->allocate($product, $variantId, $qty, $fromBatch);
            $allocations = [];

            foreach ($plan as [$level, $take]) {
                $batch = $level->batch;
                $this->move($level, $batch, $take->negated(), BigDecimal::of($batch->unit_cost), $type, $reference, $userId, $note);
                $allocations[] = new StockAllocation($batch->id, (string) $take, $batch->unit_cost);
            }

            return $allocations;
        });
    }

    /**
     * Hold stock for an invoice printed at a counter (released on settlement or void).
     *
     * @return list<StockAllocation>
     *
     * @throws InsufficientStockException
     */
    public function reserve(Product $product, ?int $variantId, string $baseQty): array
    {
        $qty = $this->positive($baseQty);

        return DB::transaction(function () use ($product, $variantId, $qty): array {
            $allocations = [];

            foreach ($this->allocate($product, $variantId, $qty) as [$level, $take]) {
                $level->qty_reserved = (string) Qty::of($level->qty_reserved)->plus($take);
                $level->save();
                $allocations[] = new StockAllocation($level->batch_id, (string) $take, $level->batch->unit_cost);
            }

            return $allocations;
        });
    }

    /**
     * @param  list<StockAllocation>  $allocations  as returned by reserve()
     */
    public function release(array $allocations): void
    {
        DB::transaction(function () use ($allocations): void {
            foreach ($allocations as $allocation) {
                $level = StockLevel::query()->where('batch_id', $allocation->batchId)->lockForUpdate()->firstOrFail();
                $reserved = Qty::of($level->qty_reserved)->minus($allocation->qty);
                $level->qty_reserved = (string) ($reserved->isNegative() ? Qty::of(0) : $reserved);
                $level->save();
            }
        });
    }

    /**
     * Signed correction (adjustments, stocktake). Positive quantities go into the given
     * batch or the default batch; negative ones come out of the given batch, or FEFO
     * across batches when none is given.
     *
     * @param  string|null  $unitCost  cost per base unit for stock added to an empty default batch
     * @return list<StockAllocation>
     *
     * @throws InsufficientStockException
     */
    public function adjust(
        Product $product,
        ?int $variantId,
        ?Batch $batch,
        string $signedQty,
        MovementType $type,
        ?Model $reference = null,
        ?int $userId = null,
        ?string $note = null,
        ?string $unitCost = null,
    ): array {
        $qty = Qty::of($signedQty);

        if ($qty->isZero()) {
            return [];
        }

        if ($qty->isNegative()) {
            return $this->issue($product, $variantId, (string) $qty->negated(), $type, $reference, $userId, $batch, $note);
        }

        return DB::transaction(function () use ($product, $variantId, $batch, $qty, $type, $reference, $userId, $note, $unitCost): array {
            $batch = $batch !== null
                ? Batch::query()->lockForUpdate()->findOrFail($batch->id)
                : $this->defaultBatch($product->id, $variantId);
            $this->guardBatchBelongs($batch, $product, $variantId);

            $level = $this->levelForUpdate($batch);

            if ($batch->isDefault() && $unitCost !== null && BigDecimal::of($batch->unit_cost)->isZero()) {
                $batch->unit_cost = (string) BigDecimal::of($unitCost)->toScale(4, RoundingMode::HalfUp);
                $batch->save();
            }

            $this->move($level, $batch, $qty, BigDecimal::of($batch->unit_cost), $type, $reference, $userId, $note);

            return [new StockAllocation($batch->id, (string) $qty, $batch->unit_cost)];
        });
    }

    /**
     * Stock that can still be sold: on hand − reserved, summed over all batches.
     */
    public function available(Product $product, ?int $variantId = null): BigDecimal
    {
        $row = $this->levelsFor($product->id, $variantId)
            ->selectRaw('COALESCE(SUM(qty_on_hand - qty_reserved), 0) AS available')
            ->toBase()
            ->first();

        return Qty::of((string) ($row->available ?? '0'));
    }

    /**
     * Totals for many products at once: [product_id][variant_id or 0] = ['on_hand' => …, 'reserved' => …, 'available' => …].
     *
     * @param  list<int>  $productIds
     * @return array<int, array<int, array{on_hand: string, reserved: string, available: string}>>
     */
    public function totals(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = StockLevel::query()
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id', 'variant_id')
            ->selectRaw('product_id, variant_id, SUM(qty_on_hand) AS on_hand, SUM(qty_reserved) AS reserved')
            ->toBase()
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $onHand = Qty::of((string) $row->on_hand);
            $reserved = Qty::of((string) $row->reserved);
            $totals[(int) $row->product_id][(int) ($row->variant_id ?? 0)] = [
                'on_hand' => (string) $onHand,
                'reserved' => (string) $reserved,
                'available' => (string) $onHand->minus($reserved),
            ];
        }

        return $totals;
    }

    /**
     * Sum of totals() over all variants of each product.
     *
     * @param  list<int>  $productIds
     * @return array<int, string> product_id => on hand
     */
    public function onHandByProduct(array $productIds): array
    {
        return collect($this->totals($productIds))
            ->map(fn (array $variants) => (string) array_reduce($variants, fn (BigDecimal $sum, array $row) => $sum->plus($row['on_hand']), Qty::of(0)))
            ->all();
    }

    /**
     * Lock the candidate batches and work out how much to take from each.
     *
     * @return list<array{0: StockLevel, 1: BigDecimal}>
     */
    private function allocate(Product $product, ?int $variantId, BigDecimal $qty, ?Batch $fromBatch = null): array
    {
        $query = $this->levelsFor($product->id, $variantId)
            ->select('stock_levels.*')
            ->join('batches', 'batches.id', '=', 'stock_levels.batch_id')
            // FEFO: batches with an expiry date first (earliest first), then the oldest.
            ->orderByRaw('batches.expiry_date IS NULL, batches.expiry_date, batches.received_at, batches.id')
            ->lockForUpdate();

        if ($fromBatch !== null) {
            $this->guardBatchBelongs($fromBatch, $product, $variantId);
            $query->where('stock_levels.batch_id', $fromBatch->id);
        }

        /** @var Collection<int, StockLevel> $levels */
        $levels = $query->get();
        $levels->load('batch');

        $remaining = $qty;
        $plan = [];

        foreach ($levels as $level) {
            if (! $remaining->isPositive()) {
                break;
            }

            $free = Qty::of($level->qty_on_hand)->minus($level->qty_reserved);

            if (! $free->isPositive()) {
                continue;
            }

            $take = $free->isLessThan($remaining) ? $free : $remaining;
            $plan[] = [$level, $take];
            $remaining = $remaining->minus($take);
        }

        if ($remaining->isPositive()) {
            if (! $this->allowsNegativeStock()) {
                throw new InsufficientStockException($product, (string) $qty, (string) $qty->minus($remaining));
            }

            // Negative stock allowed: the rest comes out of the given batch or the default batch.
            $batch = $fromBatch !== null
                ? Batch::query()->lockForUpdate()->findOrFail($fromBatch->id)
                : $this->defaultBatch($product->id, $variantId);

            foreach ($plan as $index => [$level, $take]) {
                if ($level->batch_id === $batch->id) {
                    $plan[$index][1] = $take->plus($remaining);

                    return $plan;
                }
            }

            $plan[] = [$this->levelForUpdate($batch), $remaining];
        }

        return $plan;
    }

    private function move(
        StockLevel $level,
        Batch $batch,
        BigDecimal $signedQty,
        BigDecimal $unitCost,
        MovementType $type,
        ?Model $reference,
        ?int $userId,
        ?string $note,
    ): void {
        $level->qty_on_hand = (string) Qty::of($level->qty_on_hand)->plus($signedQty);
        $level->save();

        StockMovement::create([
            'product_id' => $batch->product_id,
            'variant_id' => $batch->variant_id,
            'batch_id' => $batch->id,
            'type' => $type,
            'qty' => (string) Qty::of($signedQty),
            'unit_cost' => (string) $unitCost->toScale(4, RoundingMode::HalfUp),
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'user_id' => $userId ?? auth()->id(),
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * The product's default batch, created on first use and locked.
     */
    private function defaultBatch(int $productId, ?int $variantId): Batch
    {
        $key = Batch::defaultKey($productId, $variantId);
        $now = now();

        // Upsert so two first receipts at the same moment cannot create two default batches.
        Batch::query()->toBase()->upsert([[
            'product_id' => $productId,
            'variant_id' => $variantId,
            'unit_cost' => '0',
            'received_at' => $now,
            'default_key' => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['default_key'], ['default_key']);

        return Batch::query()->where('default_key', $key)->lockForUpdate()->firstOrFail();
    }

    private function levelForUpdate(Batch $batch): StockLevel
    {
        StockLevel::query()->toBase()->upsert([[
            'product_id' => $batch->product_id,
            'variant_id' => $batch->variant_id,
            'batch_id' => $batch->id,
            'qty_on_hand' => '0',
            'qty_reserved' => '0',
            'updated_at' => now(),
        ]], ['batch_id'], ['batch_id']);

        $level = StockLevel::query()->where('batch_id', $batch->id)->lockForUpdate()->firstOrFail();
        $level->setRelation('batch', $batch);

        return $level;
    }

    /**
     * Weighted average of the stock already in the default batch and the new receipt.
     */
    private function movingAverage(StockLevel $level, Batch $batch, BigDecimal $qty, BigDecimal $cost): BigDecimal
    {
        $onHand = Qty::of($level->qty_on_hand);

        if (! $onHand->isPositive() || BigDecimal::of($batch->unit_cost)->isZero()) {
            return $cost;
        }

        return $onHand->multipliedBy($batch->unit_cost)
            ->plus($qty->multipliedBy($cost))
            ->dividedBy($onHand->plus($qty), 4, RoundingMode::HalfUp);
    }

    /**
     * @param  array<string, mixed>  $batchData
     */
    private function usesBatches(Product $product, array $batchData): bool
    {
        return $product->track_batches
            || $product->track_expiry
            || filled($batchData['lot_no'] ?? null)
            || filled($batchData['expiry_date'] ?? null);
    }

    /**
     * @return Builder<StockLevel>
     */
    private function levelsFor(int $productId, ?int $variantId): Builder
    {
        return StockLevel::query()
            ->where('stock_levels.product_id', $productId)
            ->when(
                $variantId === null,
                fn (Builder $query) => $query->whereNull('stock_levels.variant_id'),
                fn (Builder $query) => $query->where('stock_levels.variant_id', $variantId),
            );
    }

    private function guardBatchBelongs(Batch $batch, Product $product, ?int $variantId): void
    {
        if ($batch->product_id !== $product->id || $batch->variant_id !== $variantId) {
            throw new InvalidArgumentException("Batch {$batch->id} does not belong to product {$product->id}.");
        }
    }

    private function positive(string $qty): BigDecimal
    {
        $value = Qty::of($qty);

        if (! $value->isPositive()) {
            throw new InvalidArgumentException("Quantity must be positive, got {$qty}.");
        }

        return $value;
    }

    private function allowsNegativeStock(): bool
    {
        return (bool) $this->settings->get('inventory.allow_negative_stock', false);
    }
}
