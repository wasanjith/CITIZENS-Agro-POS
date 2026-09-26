<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Enums\SupplierLedgerType;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Services\SupplierLedger;
use App\Domain\Purchasing\Support\LineMath;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a draft GRN:
 *   - stock in for every line (paid + free quantity), one batch per line for batch-tracked products;
 *     batch cost = line total after the GRN discount ÷ all base units received (free goods lower the cost)
 *   - purchase order lines received, PO → PARTIAL or RECEIVED
 *   - supplier credited with the GRN total
 *   - supplier_products.last_cost and the product's reference cost updated
 *   - journal: Dr Inventory, Cr Accounts payable
 */
class PostGoodsReceiptAction
{
    public function __construct(
        private readonly StockService $stock,
        private readonly SupplierLedger $ledger,
        private readonly FinancePosting $finance,
    ) {}

    public function handle(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $actor): GoodsReceipt {
            $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

            if ($receipt->status !== GoodsReceiptStatus::Draft) {
                throw ValidationException::withMessages(['status' => "{$receipt->number} is already {$receipt->status->label()}."]);
            }

            $lines = $receipt->lines()->with('product.units')->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'The goods receipt has no lines.']);
            }

            $order = null;
            $poLines = collect();

            if ($receipt->purchase_order_id !== null) {
                $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($receipt->purchase_order_id);

                if (! $order->status->isReceivable()) {
                    throw ValidationException::withMessages(['purchase_order_id' => "Purchase order {$order->number} is {$order->status->label()} and cannot receive goods."]);
                }

                $poLines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->lockForUpdate()->get()->keyBy('id');
            }

            // Share of the value left after the GRN discount (1 when there is none).
            $netShare = BigDecimal::of($receipt->subtotal)->isPositive()
                ? BigDecimal::of($receipt->subtotal)->minus($receipt->discount)->dividedBy($receipt->subtotal, 10, RoundingMode::HalfUp)
                : BigDecimal::one();

            foreach ($lines as $index => $line) {
                /** @var Product $product */
                $product = $line->product;
                $factor = LineMath::factor($product, $line->unit_id, "lines.{$index}.unit_id");
                $freeBase = LineMath::qty($line->free_qty)->multipliedBy($factor);
                $totalBase = LineMath::qty($line->base_qty)->plus($freeBase);

                $costPerBase = BigDecimal::of($line->line_total)
                    ->multipliedBy($netShare)
                    ->dividedBy($totalBase, 4, RoundingMode::HalfUp);

                $batch = $this->stock->receive(
                    $product,
                    $line->variant_id,
                    (string) $totalBase,
                    (string) $costPerBase,
                    [
                        'lot_no' => $line->lot_no,
                        'mfg_date' => $line->mfg_date?->toDateString(),
                        'expiry_date' => $line->expiry_date?->toDateString(),
                        'grn_line_id' => $line->id,
                    ],
                    $receipt,
                    MovementType::Grn,
                    $actor->id,
                );

                $line->batch_id = $batch->id;
                $line->save();

                if ($line->po_line_id !== null) {
                    $poLine = $poLines->get($line->po_line_id);

                    if ($poLine === null) {
                        throw ValidationException::withMessages(["lines.{$index}.product_id" => 'This line does not belong to the purchase order.']);
                    }

                    if (BigDecimal::of($line->base_qty)->isGreaterThan($poLine->outstandingBaseQty())) {
                        throw ValidationException::withMessages(["lines.{$index}.qty" => "{$product->name}: more than is still outstanding on the purchase order."]);
                    }

                    $poLine->received_base_qty = (string) LineMath::qty($poLine->received_base_qty)->plus($line->base_qty);
                    $poLine->save();
                }

                $this->rememberCost($receipt->supplier_id, $product, BigDecimal::of($line->unit_cost)->dividedBy($factor, 4, RoundingMode::HalfUp), $costPerBase);
            }

            if ($order !== null) {
                $open = $poLines->contains(fn (PurchaseOrderLine $poLine) => $poLine->outstandingBaseQty()->isPositive());
                $order->status = $open ? PurchaseOrderStatus::Partial : PurchaseOrderStatus::Received;
                $order->save();
            }

            if (BigDecimal::of($receipt->total)->isPositive()) {
                $this->ledger->credit(
                    $receipt->supplier_id,
                    SupplierLedgerType::GoodsReceipt,
                    $receipt,
                    $receipt->total,
                    $receipt->received_at,
                    $actor->id,
                    $receipt->supplier_invoice_no ? "Supplier invoice {$receipt->supplier_invoice_no}" : null,
                );
            }

            $receipt->status = GoodsReceiptStatus::Posted;
            $receipt->posted_at = now();
            $receipt->save();

            $this->finance->goodsReceived($receipt, $actor->id);

            return $receipt;
        });
    }

    /**
     * last_cost (supplier's price per base unit) prefills future purchase orders;
     * the product's reference cost (landed cost) drives the minimum-margin check.
     */
    private function rememberCost(int $supplierId, Product $product, BigDecimal $supplierCost, BigDecimal $landedCost): void
    {
        DB::table('supplier_products')->upsert(
            [[
                'supplier_id' => $supplierId,
                'product_id' => $product->id,
                'last_cost' => (string) $supplierCost,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['supplier_id', 'product_id'],
            ['last_cost', 'updated_at'],
        );

        Product::withoutSyncingToSearch(fn () => $product->update(['reference_cost' => (string) $landedCost]));
    }
}
