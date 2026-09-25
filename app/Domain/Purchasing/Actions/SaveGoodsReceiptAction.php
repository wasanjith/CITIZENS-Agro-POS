<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Support\LineMath;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a draft goods receipt (GRN). Stock only changes when it is posted.
 *
 * Expected $data (already validated):
 *   supplier_id, purchase_order_id, supplier_invoice_no, received_at, note, discount, tax
 *   lines: list of {po_line_id, product_id, variant_id, unit_id, qty, free_qty, unit_cost, lot_no, mfg_date, expiry_date}
 */
class SaveGoodsReceiptAction
{
    public function __construct(private readonly DocumentNumber $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?GoodsReceipt $receipt = null): GoodsReceipt
    {
        return DB::transaction(function () use ($data, $actor, $receipt): GoodsReceipt {
            if ($receipt === null) {
                $receipt = new GoodsReceipt([
                    'number' => $this->numbers->next('GRN'),
                    'status' => GoodsReceiptStatus::Draft,
                    'received_by' => $actor->id,
                ]);
            } else {
                $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

                if ($receipt->status !== GoodsReceiptStatus::Draft) {
                    throw ValidationException::withMessages(['status' => "{$receipt->number} is already {$receipt->status->label()}."]);
                }
            }

            $order = isset($data['purchase_order_id']) ? PurchaseOrder::query()->with('lines')->find($data['purchase_order_id']) : null;

            if ($order !== null) {
                if ($order->supplier_id !== (int) $data['supplier_id']) {
                    throw ValidationException::withMessages(['supplier_id' => 'The supplier does not match the purchase order.']);
                }
                if (! $order->status->isReceivable()) {
                    throw ValidationException::withMessages(['purchase_order_id' => "Purchase order {$order->number} is {$order->status->label()} and cannot receive goods."]);
                }
            }

            $receipt->fill(Arr::only($data, ['supplier_id', 'supplier_invoice_no', 'received_at', 'note']));
            $receipt->purchase_order_id = $order?->id;
            $receipt->discount = (string) LineMath::money($data['discount'] ?? '0');
            $receipt->tax = (string) LineMath::money($data['tax'] ?? '0');
            $receipt->save();

            $receipt->lines()->delete();

            $products = Product::query()->with('units')->whereIn('id', array_column($data['lines'], 'product_id'))->get()->keyBy('id');
            $subtotal = BigDecimal::of('0.00');

            foreach (array_values($data['lines']) as $index => $line) {
                $product = $products->get((int) $line['product_id']);
                $unitId = (int) $line['unit_id'];
                $factor = LineMath::factor($product, $unitId, "lines.{$index}.unit_id");
                $poLineId = ! empty($line['po_line_id']) ? (int) $line['po_line_id'] : null;

                if ($poLineId !== null) {
                    $poLine = $order?->lines->firstWhere('id', $poLineId);

                    if ($poLine === null || $poLine->product_id !== $product->id) {
                        throw ValidationException::withMessages(["lines.{$index}.product_id" => 'This line does not belong to the purchase order.']);
                    }

                    if (LineMath::qty($line['qty'])->multipliedBy($factor)->isGreaterThan($poLine->outstandingBaseQty())) {
                        throw ValidationException::withMessages(["lines.{$index}.qty" => 'More than is still outstanding on the purchase order. Enter extra goods as free quantity or on a separate line.']);
                    }
                }

                $lineTotal = LineMath::lineTotal($line['qty'], (string) $line['unit_cost']);
                $subtotal = $subtotal->plus($lineTotal);

                GoodsReceiptLine::create([
                    'goods_receipt_id' => $receipt->id,
                    'po_line_id' => $poLineId,
                    'product_id' => $product->id,
                    'variant_id' => ! empty($line['variant_id']) ? (int) $line['variant_id'] : null,
                    'unit_id' => $unitId,
                    'qty' => (string) LineMath::qty($line['qty']),
                    'base_qty' => (string) LineMath::qty($line['qty'])->multipliedBy($factor),
                    'unit_cost' => (string) LineMath::money($line['unit_cost']),
                    'free_qty' => (string) LineMath::qty($line['free_qty'] ?? '0'),
                    'lot_no' => $line['lot_no'] ?? null,
                    'mfg_date' => $line['mfg_date'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'line_total' => (string) $lineTotal,
                ]);
            }

            $total = $subtotal->minus($receipt->discount)->plus($receipt->tax);

            if ($total->isNegative()) {
                throw ValidationException::withMessages(['discount' => 'The discount is larger than the goods received.']);
            }

            $receipt->subtotal = (string) $subtotal;
            $receipt->total = (string) $total;
            $receipt->save();

            return $receipt->refresh();
        });
    }
}
