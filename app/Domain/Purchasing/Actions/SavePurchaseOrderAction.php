<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Support\LineMath;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a draft purchase order with its lines.
 *
 * Expected $data (already validated):
 *   supplier_id, order_date, expected_date, note, discount, tax
 *   lines: list of {product_id, variant_id, unit_id, qty, unit_cost}
 *
 * Costs (unit_cost, discount, tax) are only taken from users with catalog.cost.view.
 * When Sales Staff edit an order, the costs already on it are kept per product/unit.
 */
class SavePurchaseOrderAction
{
    public function __construct(private readonly DocumentNumber $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?PurchaseOrder $order = null): PurchaseOrder
    {
        $canCost = $actor->can('viewCost', PurchaseOrder::class);

        return DB::transaction(function () use ($data, $actor, $order, $canCost): PurchaseOrder {
            $previousCosts = [];

            if ($order === null) {
                $order = new PurchaseOrder([
                    'number' => $this->numbers->next('PO'),
                    'status' => PurchaseOrderStatus::Draft,
                    'created_by' => $actor->id,
                ]);
            } else {
                $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

                if (! $order->status->isEditable()) {
                    throw ValidationException::withMessages(['status' => "Purchase order {$order->number} can no longer be changed."]);
                }

                $previousCosts = $order->lines()->get()
                    ->mapWithKeys(fn (PurchaseOrderLine $line) => [self::lineKey($line->product_id, $line->variant_id, $line->unit_id) => $line->unit_cost])
                    ->all();
            }

            $order->fill(Arr::only($data, ['supplier_id', 'order_date', 'expected_date', 'note']));

            if ($canCost) {
                $order->discount = (string) LineMath::money($data['discount'] ?? '0');
                $order->tax = (string) LineMath::money($data['tax'] ?? '0');
            }

            // Editing a rejected order starts it over as a draft.
            $order->status = PurchaseOrderStatus::Draft;
            $order->save();

            $order->lines()->delete();

            $products = Product::query()->with('units')->whereIn('id', array_column($data['lines'], 'product_id'))->get()->keyBy('id');

            foreach (array_values($data['lines']) as $index => $line) {
                $product = $products->get((int) $line['product_id']);
                $variantId = ! empty($line['variant_id']) ? (int) $line['variant_id'] : null;
                $unitId = (int) $line['unit_id'];
                $factor = LineMath::factor($product, $unitId, "lines.{$index}.unit_id");

                $unitCost = $canCost
                    ? ($line['unit_cost'] ?? null)
                    : ($previousCosts[self::lineKey($product->id, $variantId, $unitId)] ?? null);
                $unitCost = $unitCost === null || $unitCost === '' ? null : (string) LineMath::money($unitCost);

                PurchaseOrderLine::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'unit_id' => $unitId,
                    'qty' => (string) LineMath::qty($line['qty']),
                    'base_qty' => (string) LineMath::qty($line['qty'])->multipliedBy($factor),
                    'unit_cost' => $unitCost,
                    'line_total' => (string) LineMath::lineTotal($line['qty'], $unitCost),
                ]);
            }

            $order->recalculateTotals();

            return $order->refresh();
        });
    }

    private static function lineKey(int $productId, ?int $variantId, int $unitId): string
    {
        return "{$productId}-".($variantId ?? 0)."-{$unitId}";
    }
}
