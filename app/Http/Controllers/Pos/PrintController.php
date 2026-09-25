<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Support\InvoicePresenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
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
