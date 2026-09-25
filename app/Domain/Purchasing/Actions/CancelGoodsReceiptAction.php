<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a draft GRN (nothing was posted, so nothing needs reversing). The number is
 * kept so the sequence has no gaps. A posted GRN is corrected with a supplier return.
 */
class CancelGoodsReceiptAction
{
    public function handle(GoodsReceipt $receipt): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt): GoodsReceipt {
            $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

            if ($receipt->status !== GoodsReceiptStatus::Draft) {
                throw ValidationException::withMessages(['status' => "{$receipt->number} is {$receipt->status->label()} and cannot be cancelled. Use a supplier return instead."]);
            }

            $receipt->status = GoodsReceiptStatus::Cancelled;
            $receipt->save();

            return $receipt;
        });
    }
}
