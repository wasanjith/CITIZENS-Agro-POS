<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Inventory\Support\Qty;
use App\Domain\Purchasing\Enums\SupplierLedgerType;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\SupplierReturn;
use App\Domain\Purchasing\Models\SupplierReturnLine;
use App\Domain\Purchasing\Services\SupplierLedger;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Sends goods back to a supplier from specific batches: stock out at batch cost,
 * supplier debited at the buying price (what the shop paid them).
 *
 * Expected $data (already validated):
 *   supplier_id, goods_receipt_id, return_date, reason
 *   lines: list of {batch_id, qty (base units)}
 */
class CreateSupplierReturnAction
{
    public function __construct(
        private readonly StockService $stock,
        private readonly SupplierLedger $ledger,
        private readonly DocumentNumber $numbers,
        private readonly FinancePosting $finance,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): SupplierReturn
    {
        return DB::transaction(function () use ($data, $actor): SupplierReturn {
            $return = SupplierReturn::create([
                'number' => $this->numbers->next('SRN'),
                'supplier_id' => $data['supplier_id'],
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'return_date' => $data['return_date'],
                'reason' => $data['reason'] ?? null,
                'total' => '0',
                'created_by' => $actor->id,
            ]);

            $batches = Batch::query()->with('product')->whereIn('id', array_column($data['lines'], 'batch_id'))->get()->keyBy('id');
            $total = BigDecimal::of('0.00');

            foreach ($data['lines'] as $line) {
                $batch = $batches->get((int) $line['batch_id']);
                $qty = Qty::of($line['qty']);

                $this->stock->issue($batch->product, $batch->variant_id, (string) $qty, MovementType::SupplierReturn, $return, $actor->id, $batch, $data['reason'] ?? null);

                $buyingPrice = $this->buyingPrice($batch, (int) $data['supplier_id'], isset($data['goods_receipt_id']) ? (int) $data['goods_receipt_id'] : null);
                $lineTotal = $qty->multipliedBy($buyingPrice)->toScale(2, RoundingMode::HalfUp);
                $total = $total->plus($lineTotal);

                SupplierReturnLine::create([
                    'supplier_return_id' => $return->id,
                    'product_id' => $batch->product_id,
                    'variant_id' => $batch->variant_id,
                    'batch_id' => $batch->id,
                    'qty' => (string) $qty,
                    'unit_cost' => (string) $buyingPrice,
                    'line_total' => (string) $lineTotal,
                ]);
            }

            $return->total = (string) $total;
            $return->save();

            if ($total->isPositive()) {
                $this->ledger->debit($return->supplier_id, SupplierLedgerType::Return, $return, $total, $return->return_date, $actor->id, $return->reason);
            }

            $this->finance->supplierReturned($return, $actor->id);

            return $return;
        });
    }

    /**
     * What the shop paid the supplier per base unit (owner's rule: returns go back at the
     * buying price, not at the average stock cost):
     *  1. the goods receipt line the batch came from,
     *  2. else this product's line on the goods receipt the return is made against,
     *  3. else the supplier's last price for the product,
     *  4. else the batch cost.
     * Receipt prices are net of the receipt's discount.
     */
    private function buyingPrice(Batch $batch, int $supplierId, ?int $receiptId): BigDecimal
    {
        $line = $batch->grn_line_id !== null ? GoodsReceiptLine::query()->with('goodsReceipt')->find($batch->grn_line_id) : null;

        if ($line === null && $receiptId !== null) {
            $line = GoodsReceiptLine::query()
                ->with('goodsReceipt')
                ->where('goods_receipt_id', $receiptId)
                ->where('product_id', $batch->product_id)
                ->where('variant_id', $batch->variant_id)
                ->orderBy('id')
                ->first();
        }

        if ($line !== null && BigDecimal::of($line->base_qty)->isPositive()) {
            $receipt = $line->goodsReceipt;
            $netShare = BigDecimal::of($receipt->subtotal)->isPositive()
                ? BigDecimal::of($receipt->subtotal)->minus($receipt->discount)->dividedBy($receipt->subtotal, 10, RoundingMode::HalfUp)
                : BigDecimal::one();

            return BigDecimal::of($line->unit_cost)->multipliedBy($line->qty)->multipliedBy($netShare)->dividedBy($line->base_qty, 4, RoundingMode::HalfUp);
        }

        $lastCost = DB::table('supplier_products')->where('supplier_id', $supplierId)->where('product_id', $batch->product_id)->value('last_cost');

        return BigDecimal::of((string) ($lastCost ?? $batch->unit_cost))->toScale(4, RoundingMode::HalfUp);
    }
}
