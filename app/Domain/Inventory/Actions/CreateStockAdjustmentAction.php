<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\AdjustmentReason;
use App\Domain\Inventory\Enums\AdjustmentStatus;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentLine;
use App\Domain\Inventory\Notifications\StockAdjustmentPendingApproval;
use App\Domain\Inventory\Support\Qty;
use App\Domain\System\Services\DocumentNumber;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Records a stock adjustment. Up to the approval limit (Settings → Inventory) it is
 * posted at once; above it, it waits for the Super Admin unless the creator may
 * approve adjustments.
 *
 * Expected $data (already validated):
 *   reason, note
 *   lines: list of {product_id, variant_id, batch_id, qty (signed, base units)}
 */
class CreateStockAdjustmentAction
{
    public function __construct(
        private readonly DocumentNumber $numbers,
        private readonly Settings $settings,
        private readonly ReviewStockAdjustmentAction $review,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): StockAdjustment
    {
        $adjustment = DB::transaction(function () use ($data, $actor): StockAdjustment {
            $adjustment = StockAdjustment::create([
                'number' => $this->numbers->next('ADJ'),
                'reason' => AdjustmentReason::from($data['reason']),
                'status' => AdjustmentStatus::Draft,
                'note' => $data['note'] ?? null,
                'created_by' => $actor->id,
            ]);

            $products = Product::query()->whereIn('id', array_column($data['lines'], 'product_id'))->get()->keyBy('id');
            $total = BigDecimal::of('0.00');

            foreach (array_values($data['lines']) as $index => $line) {
                $product = $products->get((int) $line['product_id']);
                $variantId = ! empty($line['variant_id']) ? (int) $line['variant_id'] : null;
                $batch = ! empty($line['batch_id']) ? Batch::find($line['batch_id']) : null;

                if ($batch !== null && ($batch->product_id !== $product->id || $batch->variant_id !== $variantId)) {
                    throw ValidationException::withMessages(["lines.{$index}.batch_id" => 'This batch belongs to another product.']);
                }

                $qty = Qty::of($line['qty']);
                $unitCost = $this->unitCost($product, $variantId, $batch);
                $total = $total->plus($qty->abs()->multipliedBy($unitCost));

                StockAdjustmentLine::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'batch_id' => $batch?->id,
                    'qty' => (string) $qty,
                    'unit_cost' => (string) $unitCost,
                ]);
            }

            $adjustment->total_value = (string) $total->toScale(2, RoundingMode::HalfUp);
            $adjustment->save();

            if ($this->needsApproval($adjustment, $actor)) {
                $adjustment->status = AdjustmentStatus::PendingApproval;
                $adjustment->save();

                return $adjustment;
            }

            return $this->review->approve($adjustment, $actor);
        });

        if ($adjustment->status === AdjustmentStatus::PendingApproval) {
            $approvers = User::permission('inventory.adjust.approve')->active()->whereKeyNot($actor->id)->get();
            Notification::send($approvers, StockAdjustmentPendingApproval::for($adjustment, $actor->name));
        }

        return $adjustment;
    }

    private function needsApproval(StockAdjustment $adjustment, User $actor): bool
    {
        $limit = BigDecimal::of((string) $this->settings->get('inventory.adjustment_approval_limit', 0));

        return BigDecimal::of($adjustment->total_value)->isGreaterThan($limit) && ! $actor->can('approveAny', StockAdjustment::class);
    }

    /**
     * Cost per base unit: the batch cost, else the default batch cost, else the product's reference cost.
     */
    private function unitCost(Product $product, ?int $variantId, ?Batch $batch): BigDecimal
    {
        $cost = $batch->unit_cost
            ?? Batch::query()->where('default_key', Batch::defaultKey($product->id, $variantId))->value('unit_cost')
            ?? $product->reference_cost
            ?? '0';

        return BigDecimal::of((string) $cost)->toScale(4, RoundingMode::HalfUp);
    }
}
