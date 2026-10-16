<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Models\GoodsReceiptInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Remove a supplier invoice photo (wrong photo, blurred, other supplier's bill).
 * The removal is kept in the audit log.
 */
class RemoveSupplierInvoiceAction
{
    public function handle(GoodsReceiptInvoice $invoice, User $user): void
    {
        $receipt = $invoice->goodsReceipt;

        $invoice->delete();
        Storage::disk('local')->delete($invoice->path);

        activity()->performedOn($receipt)->causedBy($user)->event('invoice_removed')
            ->withProperties(['file' => $invoice->path, 'original_name' => $invoice->original_name])
            ->log('Supplier invoice photo removed');
    }
}
