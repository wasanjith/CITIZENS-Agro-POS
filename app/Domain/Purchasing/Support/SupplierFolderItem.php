<?php

namespace App\Domain\Purchasing\Support;

use App\Domain\Purchasing\Enums\SupplierDocumentType;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptInvoice;
use App\Domain\Purchasing\Models\SupplierDocument;
use App\Domain\Purchasing\Models\SupplierDocumentFile;
use Illuminate\Support\Carbon;

/**
 * One tile in a supplier's folder (Purchasing → Invoices): a document added there, or the
 * invoice photos of one goods receipt.
 */
final readonly class SupplierFolderItem
{
    /**
     * @param  list<array{url: string, image: bool, name: string}>  $files
     */
    public function __construct(
        public string $key,
        public SupplierDocumentType $type,
        public Carbon $date,
        public ?string $reference,
        public ?string $amount,
        public ?string $note,
        public array $files,
        public ?Carbon $added,
        public ?string $by,
        public ?SupplierDocument $document = null,
        public ?GoodsReceipt $receipt = null,
    ) {}

    /**
     * Needs the document's files and uploader loaded.
     */
    public static function fromDocument(SupplierDocument $document): self
    {
        return new self(
            key: 'doc-'.$document->id,
            type: $document->type,
            date: $document->document_date,
            reference: $document->reference,
            amount: $document->amount,
            note: $document->note,
            files: $document->files->map(fn (SupplierDocumentFile $file) => [
                'url' => route('purchasing.invoices.file', [$document->supplier_id, $file]),
                'image' => $file->isImage(),
                'name' => $file->original_name ?? $file->fileName(),
            ])->values()->all(),
            added: $document->created_at,
            by: $document->uploader?->name,
            document: $document,
        );
    }

    /**
     * Needs the receipt's invoices and their uploaders loaded.
     */
    public static function fromGoodsReceipt(GoodsReceipt $receipt): self
    {
        $latest = $receipt->invoices->sortByDesc('id')->first();

        return new self(
            key: 'grn-'.$receipt->id,
            type: SupplierDocumentType::Invoice,
            date: $receipt->received_at->copy()->startOfDay(),
            reference: $receipt->supplier_invoice_no,
            amount: $receipt->total,
            note: null,
            files: $receipt->invoices->map(fn (GoodsReceiptInvoice $invoice) => [
                'url' => route('purchasing.goods-receipts.invoices.show', [$receipt, $invoice]),
                'image' => $invoice->isImage(),
                'name' => $invoice->original_name ?? $invoice->fileName(),
            ])->values()->all(),
            added: $latest?->created_at,
            by: $latest?->uploader?->name,
            receipt: $receipt,
        );
    }

    /**
     * Case-insensitive match on number, amount, note, file names and GRN number.
     */
    public function matches(string $search): bool
    {
        $haystack = implode(' ', array_filter([
            $this->reference, $this->amount, $this->note, $this->type->label(), $this->receipt?->number,
            ...array_column($this->files, 'name'),
        ]));

        return str_contains(mb_strtolower($haystack), mb_strtolower($search));
    }
}
