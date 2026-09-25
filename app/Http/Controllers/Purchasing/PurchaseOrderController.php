<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Inventory\Services\StockService;
use App\Domain\Purchasing\Actions\ApprovePurchaseOrderAction;
use App\Domain\Purchasing\Actions\SavePurchaseOrderAction;
use App\Domain\Purchasing\Actions\SubmitPurchaseOrderAction;
use App\Domain\Purchasing\Actions\UpdatePurchaseOrderStatusAction;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Services\ReorderSuggestions;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Concerns\PresentsProductLines;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SavePurchaseOrderRequest;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

class PurchaseOrderController extends Controller
{
    use HasListQuery, PresentsProductLines;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $orders = $this->applyListQuery(
            PurchaseOrder::query()->with(['supplier', 'creator'])->withCount('lines'),
            $request,
            searchable: ['number'],
            sortable: ['number', 'order_date', 'expected_date', 'created_at'],
            filters: [
                'status' => function (Builder $query, string $status): void {
                    $status === 'open'
                        ? $query->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent, PurchaseOrderStatus::Partial])
                        : $query->where('status', $status);
                },
                'supplier' => function (Builder $query, string $supplierId): void {
                    $query->where('supplier_id', $supplierId);
                },
                'mine' => function (Builder $query, string $value) use ($request): void {
                    if ($value === '1') {
                        $query->where('created_by', $request->user()->id);
                    }
                },
            ],
            defaultSort: 'created_at',
        )->paginate(25)->withQueryString();

        return view('purchasing.purchase-orders.index', [
            'orders' => $orders,
            'statuses' => ['open' => 'Open (to receive)', ...PurchaseOrderStatus::options()],
            'suppliers' => Supplier::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', PurchaseOrder::class);

        $supplier = $request->integer('supplier_id') ? Supplier::query()->active()->find($request->integer('supplier_id')) : null;

        return view('purchasing.purchase-orders.form', $this->formData(new PurchaseOrder([
            'supplier_id' => $supplier?->id,
            'order_date' => today(),
        ]), $request));
    }

    public function store(SavePurchaseOrderRequest $request, SavePurchaseOrderAction $save, SubmitPurchaseOrderAction $submit): RedirectResponse
    {
        $order = $save->handle($request->validated(), $request->user());

        if ($request->wantsSubmit()) {
            $submit->handle($order, $request->user());

            return redirect()->route('purchasing.purchase-orders.show', $order)->with('success', "Purchase order {$order->number} submitted for approval.");
        }

        return redirect()->route('purchasing.purchase-orders.show', $order)->with('success', "Purchase order {$order->number} saved as a draft.");
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder, StockService $stock): View
    {
        $this->authorize('view', $purchaseOrder);

        $purchaseOrder->load(['supplier', 'creator', 'approver', 'lines.product.baseUnit', 'lines.variant', 'lines.unit', 'goodsReceipts']);
        $canCost = $request->user()->can('viewCost', PurchaseOrder::class);

        return view('purchasing.purchase-orders.show', [
            'order' => $purchaseOrder,
            'canCost' => $canCost,
            'stock' => $stock->totals($purchaseOrder->lines->pluck('product_id')->unique()->values()->all()),
            'suggestedCosts' => $canCost ? $this->suggestedCosts($purchaseOrder) : [],
        ]);
    }

    public function edit(Request $request, PurchaseOrder $purchaseOrder): View
    {
        $this->authorize('update', $purchaseOrder);

        return view('purchasing.purchase-orders.form', $this->formData($purchaseOrder, $request));
    }

    public function update(SavePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, SavePurchaseOrderAction $save, SubmitPurchaseOrderAction $submit): RedirectResponse
    {
        $order = $save->handle($request->validated(), $request->user(), $purchaseOrder);

        if ($request->wantsSubmit()) {
            $submit->handle($order, $request->user());

            return redirect()->route('purchasing.purchase-orders.show', $order)->with('success', "Purchase order {$order->number} submitted for approval.");
        }

        return redirect()->route('purchasing.purchase-orders.show', $order)->with('success', "Purchase order {$order->number} saved.");
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder, SubmitPurchaseOrderAction $submit): RedirectResponse
    {
        $this->authorize('submit', $purchaseOrder);

        $submit->handle($purchaseOrder, $request->user());

        return back()->with('success', "Purchase order {$purchaseOrder->number} submitted for approval.");
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder, ApprovePurchaseOrderAction $review): RedirectResponse
    {
        $this->authorize('approve', $purchaseOrder);

        $validated = $request->validate([
            'costs' => ['required', 'array'],
            'costs.*' => ['required', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
        ], ['costs.*.required' => 'Enter the unit cost of every line.']);

        $review->approve($purchaseOrder, $request->user(), $validated['costs'], (string) ($validated['discount'] ?? '0'), (string) ($validated['tax'] ?? '0'));

        return back()->with('success', "Purchase order {$purchaseOrder->number} approved. Send it to the supplier next.");
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder, ApprovePurchaseOrderAction $review): RedirectResponse
    {
        $this->authorize('approve', $purchaseOrder);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $review->reject($purchaseOrder, $request->user(), $validated['reason']);

        return back()->with('success', "Purchase order {$purchaseOrder->number} rejected.");
    }

    public function markSent(PurchaseOrder $purchaseOrder, UpdatePurchaseOrderStatusAction $status): RedirectResponse
    {
        $this->authorize('send', $purchaseOrder);

        $status->markSent($purchaseOrder);

        return back()->with('success', "Purchase order {$purchaseOrder->number} marked as sent.");
    }

    public function cancel(PurchaseOrder $purchaseOrder, UpdatePurchaseOrderStatusAction $status): RedirectResponse
    {
        $this->authorize('cancel', $purchaseOrder);

        $status->cancel($purchaseOrder);

        return back()->with('success', "Purchase order {$purchaseOrder->number} cancelled.");
    }

    public function close(PurchaseOrder $purchaseOrder, UpdatePurchaseOrderStatusAction $status): RedirectResponse
    {
        $this->authorize('close', $purchaseOrder);

        $status->close($purchaseOrder);

        return back()->with('success', "Purchase order {$purchaseOrder->number} closed.");
    }

    /**
     * A4 PDF for the supplier (download or print).
     */
    public function pdf(PurchaseOrder $purchaseOrder, Settings $settings): PdfBuilder
    {
        $this->authorize('send', $purchaseOrder);

        return $this->pdfBuilder($purchaseOrder, $settings);
    }

    /**
     * The same PDF behind a signed link, for sharing on WhatsApp (no sign-in needed).
     */
    public function sharedPdf(PurchaseOrder $purchaseOrder, Settings $settings): PdfBuilder
    {
        abort_unless($purchaseOrder->status !== PurchaseOrderStatus::Cancelled && $purchaseOrder->approved_at !== null, 404);

        return $this->pdfBuilder($purchaseOrder, $settings)->inline();
    }

    /**
     * GET purchase-orders/reorder-suggestions?supplier_id= → lines for products at or below reorder level.
     */
    public function suggestions(Request $request, ReorderSuggestions $suggestions): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $supplier = Supplier::query()->findOrFail($request->integer('supplier_id'));
        $lines = $suggestions->forSupplier($supplier);
        $canCost = $request->user()->can('viewCost', PurchaseOrder::class);

        $rows = $this->presentLines(array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'variant_id' => $line['variant_id'],
            'unit_id' => $line['unit_id'],
            'qty' => $line['qty'],
            'unit_cost' => $canCost ? $line['last_cost'] : null,
        ], $lines), $canCost);

        return response()->json(['lines' => $rows]);
    }

    private function pdfBuilder(PurchaseOrder $order, Settings $settings): PdfBuilder
    {
        $order->load(['supplier', 'creator', 'approver', 'lines.product', 'lines.variant', 'lines.unit']);

        return Pdf::view('print.purchase-order', [
            'order' => $order,
            'shop' => $settings->group('shop'),
        ])->format('a4')->name("{$order->number}.pdf");
    }

    /**
     * Unit cost to prefill on the approval form: the line cost, else the supplier's last
     * cost for the product × unit factor, else the product's reference cost × factor.
     *
     * @return array<int, string|null> po_line id => cost
     */
    private function suggestedCosts(PurchaseOrder $order): array
    {
        $lastCosts = DB::table('supplier_products')
            ->where('supplier_id', $order->supplier_id)
            ->whereIn('product_id', $order->lines->pluck('product_id'))
            ->pluck('last_cost', 'product_id');

        $order->lines->loadMissing('product.units');

        return $order->lines->mapWithKeys(function (PurchaseOrderLine $line) use ($lastCosts): array {
            if ($line->unit_cost !== null) {
                return [$line->id => $line->unit_cost];
            }

            $perBase = $lastCosts[$line->product_id] ?? $line->product->reference_cost;
            $factor = $line->product->units->firstWhere('unit_id', $line->unit_id)->factor ?? '1';

            return [$line->id => $perBase !== null ? (string) BigDecimal::of($perBase)->multipliedBy($factor)->toScale(2, RoundingMode::HalfUp) : null];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(PurchaseOrder $order, Request $request): array
    {
        $canCost = $request->user()->can('viewCost', PurchaseOrder::class);

        $lines = old('lines') !== null
            ? array_values((array) old('lines'))
            : $order->lines()->get()->map(fn (PurchaseOrderLine $line) => [
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'unit_id' => $line->unit_id,
                'qty' => $line->qty,
                'unit_cost' => $canCost ? $line->unit_cost : null,
            ])->all();

        $supplierId = old('supplier_id') ?? $order->supplier_id;

        return [
            'order' => $order,
            'canCost' => $canCost,
            'lines' => $this->presentLines($lines, $canCost),
            'supplierName' => $supplierId ? Supplier::withTrashed()->find($supplierId)?->name : '',
        ];
    }

    /**
     * Signed link to the PDF, valid for 30 days.
     */
    public static function sharedPdfUrl(PurchaseOrder $order): string
    {
        return URL::temporarySignedRoute('purchasing.purchase-orders.shared-pdf', now()->addDays(30), ['purchase_order' => $order->id]);
    }
}
