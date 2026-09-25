<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Actions\CreateSaleReturnAction;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Enums\RefundMethod;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Sales\Services\ReturnableItems;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Sale returns: taken at the main cashier against the original invoice; listed in the
 * back office.
 */
class SaleReturnController extends Controller
{
    use HasListQuery;

    public function __construct(private readonly CurrentTerminal $currentTerminal) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SaleReturn::class);

        $returns = $this->applyListQuery(
            SaleReturn::query()->with(['sale', 'customer', 'creator']),
            $request,
            searchable: ['number', 'reason'],
            sortable: ['number', 'created_at', 'total'],
            filters: [
                'refund_method' => function (Builder $query, string $method): void {
                    $query->where('refund_method', $method);
                },
                'date' => function (Builder $query, string $date): void {
                    $query->whereDate('created_at', $date);
                },
            ],
            defaultSort: 'created_at',
        )->paginate(50)->withQueryString();

        return view('sales.returns.index', ['returns' => $returns, 'methods' => RefundMethod::options()]);
    }

    public function show(Request $request, SaleReturn $saleReturn): View
    {
        $this->authorize('view', $saleReturn);

        return view('sales.returns.show', [
            'return' => $saleReturn->load(['sale', 'customer', 'creator', 'lines.saleItem', 'lines.batches.batch']),
            'showCost' => $request->user()->can('viewCost', Product::class),
            'canReprint' => $this->currentTerminal->get()?->isMainCashier() ?? false,
        ]);
    }

    /**
     * GET /pos/returns/create?invoice=4512 (or ?sale=id): find the invoice, then pick lines.
     */
    public function create(Request $request, ReturnableItems $returnable): View
    {
        $this->authorize('create', SaleReturn::class);

        $sale = null;
        $notFound = null;

        if ($request->filled('sale')) {
            $sale = Sale::query()->find($request->integer('sale'));
        } elseif ($request->filled('invoice')) {
            $number = preg_replace('/\D/', '', (string) $request->query('invoice')) ?? '';
            $sale = $number === '' ? null : Sale::query()
                ->whereNotNull('invoice_no')
                ->where(fn (Builder $query) => $query
                    ->where('invoice_no', 'like', '%-'.str_pad(ltrim($number, '0'), 6, '0', STR_PAD_LEFT))
                    ->orWhere('invoice_no', 'like', '%'.$number))
                ->latest('invoiced_at')
                ->first();
            $notFound = $sale === null ? "No invoice ending in {$number}." : null;
        }

        return view('sales.returns.create', [
            'sale' => $sale?->load(['customer', 'invoicedTerminal']),
            'rows' => $sale !== null && $sale->canBeReturned() ? $returnable->forSale($sale) : [],
            'notFound' => $notFound,
            'methods' => RefundMethod::options(),
            'idempotencyKey' => str()->random(40),
        ]);
    }

    public function store(Request $request, Sale $sale, CreateSaleReturnAction $createReturn): RedirectResponse
    {
        $this->authorize('return', $sale);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'refund_method' => ['required', Rule::enum(RefundMethod::class)],
            'lines' => ['required', 'array'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.restock' => ['nullable', 'boolean'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
        ]);

        $lines = [];

        foreach ((array) $validated['lines'] as $itemId => $line) {
            $lines[$itemId] = [
                'qty' => isset($line['qty']) ? (string) $line['qty'] : null,
                'restock' => (bool) ($line['restock'] ?? false),
            ];
        }

        $result = $createReturn->handle(
            $sale,
            $request->user(),
            $this->currentTerminal->get(),
            $request->attributes->get('drawerSession'),
            ['reason' => $validated['reason'], 'refund_method' => $validated['refund_method'], 'lines' => $lines],
            $validated['idempotency_key'],
        );

        $return = $result['return'];
        $redirect = redirect()->route('sales.returns.show', $return)->with('success', "{$return->number}: Rs. ".number_format((float) $return->total, 2).' '.($return->refund_method === RefundMethod::Cash ? 'to pay back from the drawer.' : 'credited to the customer account.'));

        if ($result['print_job'] !== null) {
            $redirect->with('print_url', route('pos.returns.receipt', ['saleReturn' => $return, 'job' => $result['print_job']->id]));
        }

        return $redirect;
    }

    /**
     * POST /pos/returns/{return}/reprint (main terminal): a COPY on printer #0.
     */
    public function reprint(Request $request, SaleReturn $saleReturn): RedirectResponse
    {
        $this->authorize('view', $saleReturn);

        $job = PrintJob::record(PrintDocumentType::SaleReturn, $saleReturn->id, $this->currentTerminal->get()->loadMissing('printer'), $request->user()->id, isCopy: true);

        return back()->with('print_url', route('pos.returns.receipt', ['saleReturn' => $saleReturn, 'job' => $job->id]));
    }
}
