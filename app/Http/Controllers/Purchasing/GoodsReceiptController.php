<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Purchasing\Actions\CancelGoodsReceiptAction;
use App\Domain\Purchasing\Actions\PostGoodsReceiptAction;
use App\Domain\Purchasing\Actions\SaveGoodsReceiptAction;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Models\Supplier;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Concerns\PresentsProductLines;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SaveGoodsReceiptRequest;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class GoodsReceiptController extends Controller
{
    use HasListQuery, PresentsProductLines;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', GoodsReceipt::class);

        $receipts = $this->applyListQuery(
            GoodsReceipt::query()->with(['supplier', 'purchaseOrder', 'receiver']),
            $request,
            searchable: ['number', 'supplier_invoice_no'],
            sortable: ['number', 'received_at', 'total'],
            filters: [
                'status' => function (Builder $query, string $status): void {
                    $query->where('status', $status);
                },
                'supplier' => function (Builder $query, string $supplierId): void {
                    $query->where('supplier_id', $supplierId);
                },
            ],
            defaultSort: 'received_at',
        )->paginate(25)->withQueryString();

        return view('purchasing.goods-receipts.index', [
            'receipts' => $receipts,
            'statuses' => GoodsReceiptStatus::options(),
            'suppliers' => Supplier::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /**
     * ?purchase_order= prefills the outstanding lines of an approved order; ?supplier_id= a direct GRN.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('create', GoodsReceipt::class);

        $order = $request->integer('purchase_order') ? PurchaseOrder::query()->with(['lines.product.units', 'supplier'])->find($request->integer('purchase_order')) : null;

        if ($order !== null && ! $order->status->isReceivable()) {
            return redirect()->route('purchasing.purchase-orders.show', $order)->with('error', "Purchase order {$order->number} is {$order->status->label()} and cannot receive goods.");
        }

        $receipt = new GoodsReceipt([
            'supplier_id' => $order->supplier_id ?? ($request->integer('supplier_id') ?: null),
            'purchase_order_id' => $order?->id,
            'received_at' => now(),
            'discount' => '0.00',
            'tax' => '0.00',
        ]);

        $lines = $order === null ? [] : $order->lines
            ->filter(fn (PurchaseOrderLine $line) => $line->outstandingBaseQty()->isPositive())
            ->map(fn (PurchaseOrderLine $line) => [
                'po_line_id' => $line->id,
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'unit_id' => $line->unit_id,
                'qty' => (string) $line->outstandingBaseQty()->dividedBy(BigDecimal::of($line->base_qty)->dividedBy($line->qty, 6, RoundingMode::HalfUp), 3, RoundingMode::HalfUp),
                'free_qty' => '',
                'unit_cost' => $line->unit_cost,
            ])->values()->all();

        return view('purchasing.goods-receipts.form', $this->formData($receipt, $lines, $order));
    }

    public function store(SaveGoodsReceiptRequest $request, SaveGoodsReceiptAction $save, PostGoodsReceiptAction $post): RedirectResponse
    {
        $receipt = DB::transaction(function () use ($request, $save, $post): GoodsReceipt {
            $receipt = $save->handle($request->validated(), $request->user());

            return $request->wantsPost() ? $post->handle($receipt, $request->user()) : $receipt;
        });

        return redirect()->route('purchasing.goods-receipts.show', $receipt)->with('success', $request->wantsPost()
            ? "{$receipt->number} posted. Stock and the supplier balance are updated."
            : "{$receipt->number} saved as a draft. Post it to add the stock.");
    }

    public function show(GoodsReceipt $goodsReceipt): View
    {
        $this->authorize('view', $goodsReceipt);

        $goodsReceipt->load(['supplier', 'purchaseOrder', 'receiver', 'lines.product', 'lines.variant', 'lines.unit', 'lines.batch']);

        return view('purchasing.goods-receipts.show', ['receipt' => $goodsReceipt]);
    }

    public function edit(GoodsReceipt $goodsReceipt): View
    {
        $this->authorize('update', $goodsReceipt);

        $lines = $goodsReceipt->lines()->get()->map(fn (GoodsReceiptLine $line) => [
            'po_line_id' => $line->po_line_id,
            'product_id' => $line->product_id,
            'variant_id' => $line->variant_id,
            'unit_id' => $line->unit_id,
            'qty' => $line->qty,
            'free_qty' => (float) $line->free_qty ? $line->free_qty : '',
            'unit_cost' => $line->unit_cost,
            'lot_no' => $line->lot_no,
            'mfg_date' => $line->mfg_date?->toDateString(),
            'expiry_date' => $line->expiry_date?->toDateString(),
        ])->all();

        return view('purchasing.goods-receipts.form', $this->formData($goodsReceipt, $lines, $goodsReceipt->purchaseOrder()->with('lines')->first()));
    }

    public function update(SaveGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt, SaveGoodsReceiptAction $save, PostGoodsReceiptAction $post): RedirectResponse
    {
        $receipt = DB::transaction(function () use ($request, $goodsReceipt, $save, $post): GoodsReceipt {
            $receipt = $save->handle($request->validated(), $request->user(), $goodsReceipt);

            return $request->wantsPost() ? $post->handle($receipt, $request->user()) : $receipt;
        });

        return redirect()->route('purchasing.goods-receipts.show', $receipt)->with('success', $request->wantsPost()
            ? "{$receipt->number} posted. Stock and the supplier balance are updated."
            : "{$receipt->number} saved.");
    }

    public function post(Request $request, GoodsReceipt $goodsReceipt, PostGoodsReceiptAction $post): RedirectResponse
    {
        $this->authorize('post', $goodsReceipt);

        $post->handle($goodsReceipt, $request->user());

        return back()->with('success', "{$goodsReceipt->number} posted. Stock and the supplier balance are updated.");
    }

    public function cancel(GoodsReceipt $goodsReceipt, CancelGoodsReceiptAction $cancel): RedirectResponse
    {
        $this->authorize('cancel', $goodsReceipt);

        $cancel->handle($goodsReceipt);

        return back()->with('success', "{$goodsReceipt->number} cancelled.");
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function formData(GoodsReceipt $receipt, array $lines, ?PurchaseOrder $order): array
    {
        $lines = old('lines') !== null ? array_values((array) old('lines')) : $lines;
        $poLines = $order?->lines->keyBy('id');

        $rows = array_map(function (array $row) use ($poLines): array {
            $poLine = isset($row['po_line_id']) && $row['po_line_id'] ? $poLines?->get((int) $row['po_line_id']) : null;

            return [
                ...$row,
                'ordered_base' => $poLine?->base_qty,
                'outstanding_base' => $poLine !== null ? (string) $poLine->outstandingBaseQty() : null,
            ];
        }, $this->presentLines($lines, withCost: true));

        $supplierId = old('supplier_id') ?? $receipt->supplier_id;

        return [
            'receipt' => $receipt,
            'order' => $order,
            'lines' => $rows,
            'supplierName' => $supplierId ? Supplier::withTrashed()->find($supplierId)?->name : '',
        ];
    }
}
