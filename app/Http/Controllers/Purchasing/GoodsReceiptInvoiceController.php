<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Purchasing\Actions\AttachSupplierInvoiceAction;
use App\Domain\Purchasing\Actions\RemoveSupplierInvoiceAction;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photos / PDFs of the supplier's invoice on a goods receipt, taken with the phone camera
 * or picked from the gallery.
 */
class GoodsReceiptInvoiceController extends Controller
{
    public function store(Request $request, GoodsReceipt $goodsReceipt, AttachSupplierInvoiceAction $attach): RedirectResponse
    {
        $this->authorize('attachInvoice', $goodsReceipt);

        $request->validate(GoodsReceiptInvoice::uploadRules('invoice_files', required: true), [
            'invoice_files.required' => 'Take a photo of the invoice or choose a file first.',
            'invoice_files.*.mimes' => 'Only photos (JPG, PNG, WebP) or PDF files can be attached.',
            'invoice_files.*.max' => 'Each file must be smaller than '.(GoodsReceiptInvoice::MAX_KB / 1024).' MB.',
        ]);

        $saved = $attach->handle($goodsReceipt, $request->file('invoice_files', []), $request->user());

        return back()->with('success', count($saved) === 1 ? 'Invoice photo saved.' : count($saved).' invoice photos saved.');
    }

    public function show(GoodsReceipt $goodsReceipt, GoodsReceiptInvoice $invoice): StreamedResponse
    {
        $this->authorize('view', $goodsReceipt);

        abort_unless(Storage::disk('local')->exists($invoice->path), 404);

        return Storage::disk('local')->response($invoice->path, $invoice->fileName(), [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function destroy(Request $request, GoodsReceipt $goodsReceipt, GoodsReceiptInvoice $invoice, RemoveSupplierInvoiceAction $remove): RedirectResponse
    {
        $this->authorize('removeInvoice', $goodsReceipt);

        $remove->handle($invoice, $request->user());

        return back()->with('success', 'Invoice photo removed.');
    }
}
