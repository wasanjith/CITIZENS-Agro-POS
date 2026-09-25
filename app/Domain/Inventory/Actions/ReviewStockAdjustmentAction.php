<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\AdjustmentStatus;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Inventory\Support\Qty;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approve (= post the stock changes) or reject a stock adjustment.
 */
class ReviewStockAdjustmentAction
{
    public function __construct(private readonly StockService $stock) {}

    public function approve(StockAdjustment $adjustment, User $actor): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor): StockAdjustment {
            $adjustment = $this->lockOpen($adjustment);

            foreach ($adjustment->lines()->with(['product', 'batch'])->orderBy('id')->get() as $line) {
                $qty = Qty::of($line->qty);

                $this->stock->adjust(
                    $line->product,
                    $line->variant_id,
                    $line->batch,
                    (string) $qty,
                    $adjustment->reason->movementType($qty->isPositive()),
                    $adjustment,
                    $actor->id,
                    $adjustment->note,
                    $line->unit_cost,
                );
            }

            $adjustment->status = AdjustmentStatus::Approved;
            $adjustment->approved_by = $actor->id;
            $adjustment->approved_at = now();
            $adjustment->save();

            return $adjustment;
        });
    }

    public function reject(StockAdjustment $adjustment, User $actor, string $reason): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor, $reason): StockAdjustment {
            $adjustment = $this->lockOpen($adjustment);

            $adjustment->status = AdjustmentStatus::Rejected;
            $adjustment->approved_by = $actor->id;
            $adjustment->approved_at = now();
            $adjustment->rejection_reason = $reason;
            $adjustment->save();

            return $adjustment;
        });
    }

    private function lockOpen(StockAdjustment $adjustment): StockAdjustment
    {
        $adjustment = StockAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);

        if (! in_array($adjustment->status, [AdjustmentStatus::Draft, AdjustmentStatus::PendingApproval], true)) {
            throw ValidationException::withMessages(['status' => "{$adjustment->number} is already {$adjustment->status->label()}."]);
        }

        return $adjustment;
    }
}
