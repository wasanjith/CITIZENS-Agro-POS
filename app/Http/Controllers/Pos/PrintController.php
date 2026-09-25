<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Customers\Models\CustomerLedgerEntry;
use App\Domain\Customers\Models\CustomerPayment;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Quotation;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Sales\Support\InvoicePresenter;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Invoice pages for the thermal printers and A4.
 *
 * Every PC prints the same way: a hidden iframe loads the 80 mm page, which calls
 * print() once the Sinhala font is ready. Chrome runs with --kiosk-printing, so the page
 * goes silently to that PC's own (default) thermal printer. The page then tells the
 * parent window, which confirms the print job (health hint "Check printer" otherwise).
 */
class PrintController extends Controller
{
    public function __construct(
        private readonly CurrentTerminal $currentTerminal,
        private readonly InvoicePresenter $presenter,
        private readonly Settings $settings,
    ) {}

    /**
     * GET /pos/sales/{sale}/invoice?job=: prints automatically when opened for a print job
     * that has not printed yet; otherwise a screen preview (marked COPY).
     */
    public function invoice(Request $request, Sale $sale): View
    {
        $this->authorize('view', $sale);
        abort_if($sale->invoice_no === null, 404);

        $terminal = $this->currentTerminal->get();
        $job = $request->filled('job')
            ? PrintJob::query()->where('document_type', PrintDocumentType::Invoice)->where('document_id', $sale->id)->find($request->integer('job'))
            : null;

        return view('print.invoice', [
            ...$this->presenter->present($sale, $this->presenter->language($terminal, $request->query('lang')), $job->is_copy ?? true),
            'autoprint' => $job !== null && $job->printed_at === null,
            'printJobId' => $job?->id,
            'paperWidth' => $terminal->printer->paper_width_mm ?? 80,
        ]);
    }

    /**
     * GET /pos/sales/{sale}/invoice.pdf: A4 invoice for customers who ask for one.
     */
    public function invoicePdf(Request $request, Sale $sale): PdfBuilder
    {
        $this->authorize('view', $sale);
        abort_if($sale->invoice_no === null, 404);

        $language = $this->presenter->language(null, $request->query('lang'));

        return Pdf::view('print.invoice-a4', $this->presenter->present($sale, $language, $sale->print_count > 1))
            ->format('a4')
            ->name("{$sale->invoice_no}.pdf");
    }

    /**
     * POST /api/pos/print-jobs/{job}/printed: the terminal's browser sent the page to its printer.
     */
    public function printed(PrintJob $printJob): JsonResponse
    {
        $terminal = $this->currentTerminal->get();
        abort_unless($printJob->terminal_id === null || $printJob->terminal_id === $terminal?->id, 403);

        if ($printJob->printed_at === null) {
            $printJob->forceFill(['printed_at' => now()])->save();

            if ($printJob->document_type === PrintDocumentType::Test) {
                $printJob->printer?->forceFill(['last_test_at' => now()])->save();
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * GET /pos/customer-payments/{payment}/receipt?job=: 80 mm payment receipt (main printer).
     */
    public function paymentReceipt(Request $request, CustomerPayment $customerPayment): View
    {
        $this->authorize('view', $customerPayment);

        $customerPayment->load(['customer', 'allocations.sale', 'receivedBy']);
        $balanceNow = CustomerLedgerEntry::query()
            ->where('customer_id', $customerPayment->customer_id)
            ->where('id', '<=', (int) CustomerLedgerEntry::query()->where('reference_type', $customerPayment->getMorphClass())->where('reference_id', $customerPayment->id)->value('id'))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance')
            ->toBase()
            ->first()
            ->balance ?? '0';

        return $this->document($request, PrintDocumentType::PaymentReceipt, $customerPayment->id, 'print.payment-receipt', [
            'payment' => $customerPayment,
            'balanceNow' => (string) $balanceNow,
            'balanceBefore' => (string) ((float) $balanceNow + (float) $customerPayment->amount),
        ]);
    }

    /**
     * GET /pos/sales/{sale}/credit-bill?job=: 80 mm credit bill printed at the main cashier
     * when a credit sale is settled. The customer signs it and the owner stamps the shop
     * seal; the shop keeps it.
     */
    public function creditBill(Request $request, Sale $sale): View
    {
        $this->authorize('view', $sale);
        abort_unless($sale->isCreditSale(), 404);

        $sale->load(['items', 'customer', 'settledBy']);
        $balance = CustomerLedgerEntry::query()
            ->where('customer_id', $sale->customer_id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance')
            ->toBase()
            ->first()
            ->balance ?? '0';

        return $this->document($request, PrintDocumentType::CreditBill, $sale->id, 'print.credit-bill', [
            'sale' => $sale,
            'accountBalance' => (string) $balance,
        ]);
    }

    /**
     * POST /pos/sales/{sale}/credit-bill/reprint (main terminal): a COPY on printer #0.
     */
    public function reprintCreditBill(Request $request, Sale $sale): RedirectResponse
    {
        $this->authorize('view', $sale);
        abort_unless($sale->isCreditSale(), 404);

        $job = PrintJob::record(PrintDocumentType::CreditBill, $sale->id, $this->currentTerminal->get()->loadMissing('printer'), $request->user()->id, isCopy: true);

        return back()->with('print_url', route('pos.sales.credit-bill', ['sale' => $sale, 'job' => $job->id]));
    }

    /**
     * GET /pos/returns/{return}/receipt?job=: 80 mm return receipt (main printer).
     */
    public function returnReceipt(Request $request, SaleReturn $saleReturn): View
    {
        $this->authorize('view', $saleReturn);

        return $this->document($request, PrintDocumentType::SaleReturn, $saleReturn->id, 'print.sale-return', [
            'return' => $saleReturn->load(['sale', 'customer', 'lines.saleItem', 'creator']),
        ]);
    }

    /**
     * GET /pos/quotations/{quotation}/print?job=: 80 mm quotation (counter printer).
     */
    public function quotation(Request $request, Quotation $quotation): View
    {
        $this->authorize('view', $quotation);

        return $this->document($request, PrintDocumentType::Quotation, $quotation->id, 'print.quotation', [
            'quotation' => $quotation->load(['lines', 'customer', 'creator']),
        ]);
    }

    /**
     * An 80 mm document: prints itself when opened for a print job that has not printed yet,
     * otherwise a screen preview marked COPY.
     *
     * @param  array<string, mixed>  $data
     */
    private function document(Request $request, PrintDocumentType $type, int $documentId, string $view, array $data): View
    {
        $terminal = $this->currentTerminal->get();
        $job = $request->filled('job')
            ? PrintJob::query()->where('document_type', $type)->where('document_id', $documentId)->find($request->integer('job'))
            : null;

        return view($view, [
            ...$data,
            'language' => $this->presenter->language($terminal, $request->query('lang')),
            'shop' => $this->settings->group('shop'),
            'isCopy' => $job->is_copy ?? true,
            'autoprint' => $job !== null && $job->printed_at === null,
            'printJobId' => $job?->id,
            'paperWidth' => $terminal?->printer->paper_width_mm ?? 80,
        ]);
    }

    /**
     * GET /pos/printers/test-page?job=: Sinhala conjunct sample and an 80 mm ruler,
     * requested from the Printers page for this terminal.
     */
    public function testPage(Request $request): View
    {
        $terminal = $this->currentTerminal->get()->loadMissing('printer');
        $job = $request->filled('job')
            ? PrintJob::query()->where('document_type', PrintDocumentType::Test)->where('terminal_id', $terminal->id)->find($request->integer('job'))
            : null;

        return view('print.test', [
            'terminal' => $terminal,
            'printer' => $terminal->printer,
            'autoprint' => $job !== null && $job->printed_at === null,
            'printJobId' => $job?->id,
            'paperWidth' => $terminal->printer->paper_width_mm ?? 80,
        ]);
    }
}
