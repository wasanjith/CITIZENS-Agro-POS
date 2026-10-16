<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Purchasing\Actions\RemoveSupplierDocumentAction;
use App\Domain\Purchasing\Actions\SaveSupplierDocumentAction;
use App\Domain\Purchasing\Enums\SupplierDocumentType;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptInvoice;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierDocument;
use App\Domain\Purchasing\Models\SupplierDocumentFile;
use App\Domain\Purchasing\Support\SupplierFolderItem;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Purchasing → Invoices: one folder per supplier holding its invoices, bank deposit slips,
 * receipts and other papers, plus the invoice photos taken on its goods receipts.
 */
class SupplierDocumentController extends Controller
{
    private const PER_PAGE = 24;

    /**
     * All suppliers as folders, searchable by name.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', SupplierDocument::class);

        $byType = fn (SupplierDocumentType $type) => fn (Builder $query) => $query->where('type', $type->value);

        $suppliers = Supplier::query()
            ->select(['id', 'name', 'contact_person', 'phone', 'is_active'])
            ->withCount([
                'documents',
                'documents as invoice_count' => $byType(SupplierDocumentType::Invoice),
                'documents as deposit_slip_count' => $byType(SupplierDocumentType::DepositSlip),
                'documents as receipt_count' => $byType(SupplierDocumentType::Receipt),
                'goodsReceipts as grn_invoice_count' => fn (Builder $query) => $query->whereHas('invoices'),
            ])
            ->withMax('documents as last_document_at', 'created_at')
            ->withMax('goodsReceiptInvoices as last_grn_invoice_at', 'created_at')
            // Inactive suppliers only show while their folder still holds papers.
            ->where(fn (Builder $query) => $query->where('is_active', true)->orHas('documents')->orHas('goodsReceiptInvoices'))
            ->orderBy('name')
            ->get()
            ->map(function (Supplier $supplier): array {
                $last = collect([$supplier->getAttribute('last_document_at'), $supplier->getAttribute('last_grn_invoice_at')])->filter()->max();
                $invoices = (int) $supplier->getAttribute('invoice_count') + (int) $supplier->getAttribute('grn_invoice_count');
                $slips = (int) $supplier->getAttribute('deposit_slip_count');
                $receipts = (int) $supplier->getAttribute('receipt_count');
                $total = (int) $supplier->getAttribute('documents_count') + (int) $supplier->getAttribute('grn_invoice_count');

                return [
                    'supplier' => $supplier,
                    'total' => $total,
                    'counts' => [
                        SupplierDocumentType::Invoice->value => $invoices,
                        SupplierDocumentType::DepositSlip->value => $slips,
                        SupplierDocumentType::Receipt->value => $receipts,
                        SupplierDocumentType::Other->value => $total - $invoices - $slips - $receipts,
                    ],
                    'last' => $last ? Carbon::parse($last) : null,
                ];
            });

        $sort = $request->query('sort') === 'recent' ? 'recent' : 'name';

        if ($sort === 'recent') {
            $suppliers = $suppliers->sortByDesc(fn (array $folder) => $folder['last']?->getTimestamp() ?? 0)->values();
        }

        return view('purchasing.supplier-documents.index', [
            'folders' => $suppliers,
            'sort' => $sort,
        ]);
    }

    /**
     * One supplier's folder.
     */
    public function show(Request $request, Supplier $supplier): View
    {
        $this->authorize('viewAny', SupplierDocument::class);

        $all = $this->folderItems($supplier);

        $type = SupplierDocumentType::tryFrom((string) $request->query('type'));
        $year = (int) $request->query('year') ?: null;
        $search = trim((string) $request->query('search'));

        $filtered = $all
            ->filter(fn (SupplierFolderItem $item) => ($type === null || $item->type === $type)
                && ($year === null || $item->date->year === $year)
                && ($search === '' || $item->matches($search)))
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $filtered->forPage($page, self::PER_PAGE)->values(),
            $filtered->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('purchasing.supplier-documents.show', [
            'supplier' => $supplier,
            'balance' => $supplier->balance(),
            'items' => $paginator,
            'total' => $all->count(),
            'counts' => collect(SupplierDocumentType::cases())
                ->mapWithKeys(fn (SupplierDocumentType $t) => [$t->value => $all->filter(fn (SupplierFolderItem $item) => $item->type === $t)->count()])
                ->all(),
            'years' => $all->map(fn (SupplierFolderItem $item) => $item->date->year)->unique()->sortDesc()->values()->all(),
            'type' => $type,
            'year' => $year,
        ]);
    }

    public function store(Request $request, Supplier $supplier, SaveSupplierDocumentAction $save): RedirectResponse
    {
        $this->authorize('create', SupplierDocument::class);

        $data = $request->validate([
            'type' => ['required', Rule::enum(SupplierDocumentType::class)],
            'document_date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:60'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:500'],
            ...GoodsReceiptInvoice::uploadRules('document_files', required: true),
        ], [
            'document_date.before_or_equal' => 'The date cannot be in the future.',
            'document_files.required' => 'Take a photo of the document or choose a file first.',
            'document_files.*.mimes' => 'Only photos (JPG, PNG, WebP) or PDF files can be added.',
            'document_files.*.max' => 'Each file must be smaller than '.(GoodsReceiptInvoice::MAX_KB / 1024).' MB.',
        ]);

        $document = $save->handle($supplier, $data, $request->file('document_files', []), $request->user());

        return redirect()->route('purchasing.invoices.show', $supplier)
            ->with('success', $document->type->label().' saved in the '.$supplier->name.' folder.');
    }

    public function file(Supplier $supplier, SupplierDocumentFile $file): StreamedResponse
    {
        $this->authorize('viewAny', SupplierDocument::class);

        abort_unless(SupplierDocument::query()->whereKey($file->supplier_document_id)->where('supplier_id', $supplier->id)->exists(), 404);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->response($file->path, $file->fileName(), [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function destroy(Request $request, Supplier $supplier, SupplierDocument $document, RemoveSupplierDocumentAction $remove): RedirectResponse
    {
        $this->authorize('delete', $document);

        $remove->handle($document, $request->user());

        return back()->with('success', $document->type->label().' removed.');
    }

    /**
     * Everything in the supplier's folder, newest first: the documents added here plus one
     * invoice per goods receipt that has invoice photos.
     *
     * @return Collection<int, SupplierFolderItem>
     */
    private function folderItems(Supplier $supplier): Collection
    {
        $documents = $supplier->documents()->with(['files', 'uploader'])->get()
            ->map(fn (SupplierDocument $document) => SupplierFolderItem::fromDocument($document));

        $receipts = $supplier->goodsReceipts()->whereHas('invoices')->with(['invoices.uploader'])->get()
            ->map(fn (GoodsReceipt $receipt) => SupplierFolderItem::fromGoodsReceipt($receipt));

        return $documents->toBase()->concat($receipts)
            ->sortBy([
                fn (SupplierFolderItem $a, SupplierFolderItem $b) => $b->date <=> $a->date,
                fn (SupplierFolderItem $a, SupplierFolderItem $b) => ($b->added?->getTimestamp() ?? 0) <=> ($a->added?->getTimestamp() ?? 0),
            ])
            ->values();
    }
}
