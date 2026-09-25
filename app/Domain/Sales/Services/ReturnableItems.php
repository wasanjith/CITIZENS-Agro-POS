<?php

namespace App\Domain\Sales\Services;

use App\Domain\Inventory\Support\Qty;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SaleReturnLine;
use App\Domain\Sales\Models\SaleReturnLineBatch;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What can still come back on each line of a settled invoice, and for how much.
 *
 * A line's net value is its line total less its share of the bill discount (shared in
 * proportion to the line totals; the last line takes the rounding so the lines add up
 * to the invoice total exactly).
 */
class ReturnableItems
{
    /**
     * Rows keyed by sale_item_id.
     *
     * @return array<int, array{item: SaleItem, net: BigDecimal, returned_base: BigDecimal, returned_qty: BigDecimal, returned_amount: BigDecimal, returnable_base: BigDecimal, returnable_qty: BigDecimal, refundable: BigDecimal, batches: list<array{batch_id: int, remaining: BigDecimal, unit_cost: string}>}>
     */
    public function forSale(Sale $sale): array
    {
        $items = SaleItem::query()->with('batches')->where('sale_id', $sale->id)->orderBy('line_no')->get();
        $itemIds = $items->pluck('id')->all();

        $returned = SaleReturnLine::query()
            ->whereIn('sale_item_id', $itemIds)
            ->groupBy('sale_item_id')
            ->selectRaw('sale_item_id, SUM(base_qty) AS base_qty, SUM(amount) AS amount')
            ->toBase()
            ->get()
            ->keyBy('sale_item_id');

        $returnedPerBatch = SaleReturnLineBatch::query()
            ->join('sale_return_lines', 'sale_return_lines.id', '=', 'sale_return_line_batches.sale_return_line_id')
            ->whereIn('sale_return_lines.sale_item_id', $itemIds)
            ->groupBy('sale_return_lines.sale_item_id', 'sale_return_line_batches.batch_id')
            ->selectRaw('sale_return_lines.sale_item_id, sale_return_line_batches.batch_id, SUM(sale_return_line_batches.base_qty) AS base_qty')
            ->toBase()
            ->get();

        $nets = $this->netValues($sale, $items->all());
        $rows = [];

        foreach ($items as $item) {
            $returnedBase = Qty::of((string) ($returned[$item->id]->base_qty ?? '0'));
            $returnedAmount = Money::of((string) ($returned[$item->id]->amount ?? '0'));
            $returnableBase = Qty::of($item->base_qty)->minus($returnedBase);
            $factor = Qty::of($item->factor);
            $net = $nets[$item->id];
            $refundable = $net->minus($returnedAmount);

            $batches = [];

            foreach ($item->batches->sortBy('id') as $allocation) {
                $back = $returnedPerBatch->first(fn ($row) => (int) $row->sale_item_id === $item->id && (int) $row->batch_id === $allocation->batch_id);
                $batches[] = [
                    'batch_id' => $allocation->batch_id,
                    'remaining' => Qty::of($allocation->base_qty)->minus((string) ($back->base_qty ?? '0')),
                    'unit_cost' => (string) $allocation->unit_cost,
                ];
            }

            $rows[$item->id] = [
                'item' => $item,
                'net' => $net,
                'returned_base' => $returnedBase,
                'returned_qty' => $factor->isZero() ? $returnedBase : $returnedBase->dividedBy($factor, Qty::SCALE, RoundingMode::HalfUp),
                'returned_amount' => $returnedAmount,
                'returnable_base' => $returnableBase,
                'returnable_qty' => $factor->isZero() ? $returnableBase : $returnableBase->dividedBy($factor, Qty::SCALE, RoundingMode::HalfUp),
                'refundable' => $refundable->isNegative() ? Money::zero() : $refundable,
                'batches' => $batches,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<SaleItem>  $items
     * @return array<int, BigDecimal> sale_item_id => net value
     */
    private function netValues(Sale $sale, array $items): array
    {
        $billDiscount = Money::of($sale->bill_discount);
        $lineTotals = array_reduce($items, fn (BigDecimal $sum, SaleItem $item) => $sum->plus($item->line_total), Money::zero());
        $nets = [];
        $shared = Money::zero();

        foreach ($items as $index => $item) {
            $lineTotal = Money::of($item->line_total);

            if ($billDiscount->isZero() || $lineTotals->isZero()) {
                $nets[$item->id] = $lineTotal;

                continue;
            }

            $share = $index === count($items) - 1
                ? $billDiscount->minus($shared)
                : $billDiscount->multipliedBy($lineTotal)->dividedBy($lineTotals, 2, RoundingMode::HalfUp);
            $shared = $shared->plus($share);
            $nets[$item->id] = $lineTotal->minus($share);
        }

        return $nets;
    }
}
