<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\Enums\QuotationStatus;
use App\Domain\Sales\Models\Quotation;
use App\Domain\Sales\Support\InvoicePresenter;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Quotations in the back office: list, details, A4 PDF, cancel. They are made and
 * billed on the counter screen.
 */
class QuotationController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Quotation::class);

        $quotations = $this->applyListQuery(
            Quotation::query()->with(['customer', 'creator', 'convertedSale']),
            $request,
            searchable: ['number', 'customer_name'],
            sortable: ['number', 'created_at', 'total', 'valid_until'],
            filters: [
                'status' => function (Builder $query, string $status): void {
                    match ($status) {
                        'open' => $query->where('status', QuotationStatus::Open)->whereDate('valid_until', '>=', today()),
                        'expired' => $query->where(fn (Builder $inner) => $inner->where('status', QuotationStatus::Expired)->orWhere(fn (Builder $open) => $open->where('status', QuotationStatus::Open)->whereDate('valid_until', '<', today()))),
                        default => $query->where('status', $status),
                    };
                },
            ],
            defaultSort: 'created_at',
        )->paginate(50)->withQueryString();

        return view('sales.quotations.index', ['quotations' => $quotations, 'statuses' => QuotationStatus::options()]);
    }

    public function show(Quotation $quotation): View
    {
        $this->authorize('view', $quotation);

        return view('sales.quotations.show', ['quotation' => $quotation->load(['lines', 'customer', 'creator', 'terminal', 'convertedSale', 'priceList'])]);
    }

    public function pdf(Request $request, Quotation $quotation, InvoicePresenter $presenter, Settings $settings): PdfBuilder
    {
        $this->authorize('view', $quotation);

        return Pdf::view('print.quotation-a4', [
            'quotation' => $quotation->load(['lines', 'customer', 'creator']),
            'language' => $presenter->language(null, $request->query('lang')),
            'shop' => $settings->group('shop'),
        ])->format('a4')->name("{$quotation->number}.pdf");
    }

    public function cancel(Quotation $quotation): RedirectResponse
    {
        $this->authorize('cancel', $quotation);

        if ($quotation->status !== QuotationStatus::Open) {
            return back()->with('error', "{$quotation->number} is {$quotation->status->label()}; it cannot be cancelled.");
        }

        $quotation->forceFill(['status' => QuotationStatus::Cancelled])->save();

        return back()->with('success', "{$quotation->number} cancelled.");
    }
}
