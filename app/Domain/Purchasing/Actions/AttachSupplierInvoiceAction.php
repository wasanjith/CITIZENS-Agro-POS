<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptInvoice;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Save photos / PDFs of the supplier's invoice for a goods receipt. Each supplier has its
 * own folder on the local disk, and the files are named by the receipt date and GRN number:
 * supplier-invoices/cic-agri-businesses/2026-10-15_GRN-2026-000012_1.jpg
 */
class AttachSupplierInvoiceAction
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @return list<GoodsReceiptInvoice>
     */
    public function handle(GoodsReceipt $receipt, array $files, User $user): array
    {
        if ($receipt->status === GoodsReceiptStatus::Cancelled) {
            throw ValidationException::withMessages(['invoice_files' => "{$receipt->number} is cancelled."]);
        }

        $disk = Storage::disk('local');
        $folder = $receipt->supplier->invoiceFolder();
        $prefix = $receipt->received_at->format('Y-m-d').'_'.preg_replace('/[^A-Za-z0-9-]/', '', $receipt->number);
        $sequence = $receipt->invoices()->count();
        $saved = [];

        foreach ($files as $file) {
            $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
            $extension = $extension === 'jpeg' ? 'jpg' : $extension;

            do {
                $name = $prefix.'_'.(++$sequence).'.'.$extension;
            } while ($disk->exists("{$folder}/{$name}"));

            $path = $file->storeAs($folder, $name, 'local');

            if ($path === false) {
                throw ValidationException::withMessages(['invoice_files' => "Could not save {$file->getClientOriginalName()}. Is the server disk full?"]);
            }

            $saved[] = $receipt->invoices()->create([
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255) ?: null,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => (int) $file->getSize(),
                'uploaded_by' => $user->id,
            ]);
        }

        if ($saved !== []) {
            activity()->performedOn($receipt)->causedBy($user)->event('invoice_attached')
                ->withProperties(['files' => array_map(fn (GoodsReceiptInvoice $invoice) => $invoice->path, $saved)])
                ->log(count($saved) === 1 ? 'Supplier invoice photo attached' : count($saved).' supplier invoice photos attached');
        }

        return $saved;
    }
}
