<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\CounterEvent;
use App\Domain\Sales\Models\Sale;
use App\Http\Controllers\Concerns\HasListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Back-office list of invoices and one invoice's details (items, payments, batches, trail).
 */
class SaleController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Sale::class);

        $sales = $this->applyListQuery(
            Sale::query()->with(['invoicedBy', 'invoicedTerminal', 'settledBy'])->whereNotNull('invoice_no'),
            $request,
            searchable: ['invoice_no'],
            sortable: ['invoice_no', 'invoiced_at', 'total'],
            filters: [
                'status' => function (Builder $query, string $status): void {
                    $query->where('status', $status);
                },
                'terminal_id' => function (Builder $query, string $terminal): void {
                    $query->where('invoiced_terminal_id', $terminal);
                },
                'date' => function (Builder $query, string $date): void {
                    $query->whereDate('invoiced_at', $date);
                },
            ],
            defaultSort: 'invoiced_at',
        )->simplePaginate(50)->withQueryString();

        return view('sales.index', [
            'sales' => $sales,
            'statuses' => SaleStatus::options(),
            'terminals' => Terminal::query()->orderByRaw('counter_no IS NULL, counter_no')->get(),
        ]);
    }

    public function show(Request $request, Sale $sale): View
    {
        $this->authorize('view', $sale);

        $sale->load(['items.batches.batch', 'items.unit', 'payments.confirmedBy', 'invoicedBy', 'invoicedTerminal', 'settledBy', 'voidedBy', 'priceList', 'customer', 'returns', 'allocations.payment']);
        $atMain = app(CurrentTerminal::class)->get()?->isMainCashier() ?? false;

        return view('sales.show', [
            'sale' => $sale,
            'showCost' => $request->user()->can('viewCost', Product::class),
            'events' => CounterEvent::query()->with('user')->where('sale_id', $sale->id)->orWhere(fn ($query) => $query->where('cart_uuid', $sale->cart_uuid)->whereNotNull('cart_uuid'))->oldest('id')->limit(100)->get(),
            'canVoidHere' => $request->user()->can('void', $sale) && in_array($sale->status, [SaleStatus::Invoiced, SaleStatus::Settled], true),
            'canReprintCreditBill' => $atMain && $sale->isCreditSale() && $request->user()->can('pos.settle'),
            'canReturnHere' => $atMain && $sale->canBeReturned() && $request->user()->can('return', $sale),
        ]);
    }
}
