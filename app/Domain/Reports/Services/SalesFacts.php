<?php

namespace App\Domain\Reports\Services;

use App\Domain\Sales\Enums\SaleStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The rows behind every sales and profit report. A sale counts on the day it was settled
 * (like the journal); a return counts on the day it was taken, against the original sale's
 * counter, staff member and customer. A voided sale does not count at all.
 *
 * Columns of both queries: kind ('sale' | 'return'), sale_id, at, terminal_id, user_id,
 * customer_id, qty, amount (after all discounts, tax included), tax, discount, gross, cost.
 * Amounts of returns are negative.
 */
class SalesFacts
{
    /**
     * Statuses of a sale that was settled (and possibly returned later).
     *
     * @var list<SaleStatus>
     */
    public const SOLD = [SaleStatus::Settled, SaleStatus::PartiallyReturned, SaleStatus::Returned];

    /**
     * One row per sale and per return: exact bill totals (for day, hour, counter, staff and customer figures).
     */
    public function bills(Carbon $from, Carbon $end): Builder
    {
        $sales = DB::table('sales as s')
            ->whereIn('s.status', self::soldValues())
            ->where('s.settled_at', '>=', $from)
            ->where('s.settled_at', '<', $end)
            ->selectRaw("'sale' AS kind, s.id AS sale_id, s.settled_at AS at, s.invoiced_terminal_id AS terminal_id, s.invoiced_by AS user_id, s.customer_id,
                0 AS qty, s.total AS amount, s.tax_total AS tax, s.line_discount_total + s.bill_discount AS discount, s.subtotal AS gross, COALESCE(s.cost_total, 0) AS cost");

        $returns = DB::table('sale_returns as r')
            ->join('sales as s', 's.id', '=', 'r.sale_id')
            ->where('r.created_at', '>=', $from)
            ->where('r.created_at', '<', $end)
            ->selectRaw("'return' AS kind, s.id AS sale_id, r.created_at AS at, s.invoiced_terminal_id AS terminal_id, s.invoiced_by AS user_id, s.customer_id,
                0 AS qty, -r.total AS amount, -ROUND(r.total * s.tax_total / NULLIF(s.total, 0), 2) AS tax, 0 AS discount, 0 AS gross, -r.cost_total AS cost");

        return $sales->unionAll($returns);
    }

    /**
     * One row per sold line and per returned line (for item, category and brand figures).
     * A line's amount is its share of the bill after the bill discount.
     */
    public function lines(Carbon $from, Carbon $end): Builder
    {
        $share = 'COALESCE(s.total / NULLIF(s.subtotal - s.line_discount_total, 0), 0)';

        $sold = DB::table('sale_items as i')
            ->join('sales as s', 's.id', '=', 'i.sale_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->whereIn('s.status', self::soldValues())
            ->where('s.settled_at', '>=', $from)
            ->where('s.settled_at', '<', $end)
            ->selectRaw("'sale' AS kind, s.id AS sale_id, s.settled_at AS at, s.invoiced_terminal_id AS terminal_id, s.invoiced_by AS user_id, s.customer_id,
                i.product_id, p.category_id, p.brand_id, i.base_qty AS qty,
                ROUND(i.line_total * {$share}, 2) AS amount, ROUND(i.tax_amount * {$share}, 2) AS tax,
                i.discount_amount + i.line_total - ROUND(i.line_total * {$share}, 2) AS discount,
                i.unit_price * i.qty AS gross, COALESCE(i.cost_total, 0) AS cost");

        $returned = DB::table('sale_return_lines as rl')
            ->join('sale_returns as r', 'r.id', '=', 'rl.sale_return_id')
            ->join('sale_items as i', 'i.id', '=', 'rl.sale_item_id')
            ->join('sales as s', 's.id', '=', 'r.sale_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('r.created_at', '>=', $from)
            ->where('r.created_at', '<', $end)
            ->selectRaw("'return' AS kind, s.id AS sale_id, r.created_at AS at, s.invoiced_terminal_id AS terminal_id, s.invoiced_by AS user_id, s.customer_id,
                i.product_id, p.category_id, p.brand_id, -rl.base_qty AS qty,
                -rl.amount AS amount, -ROUND(rl.amount * s.tax_total / NULLIF(s.total, 0), 2) AS tax,
                0 AS discount, 0 AS gross, -rl.cost_total AS cost");

        return $sold->unionAll($returned);
    }

    /**
     * Standard sums over a facts query, grouped by the caller.
     */
    public static function sums(): string
    {
        return "COUNT(DISTINCT CASE WHEN f.kind = 'sale' THEN f.sale_id END) AS invoices,
            SUM(f.qty) AS qty,
            SUM(CASE WHEN f.kind = 'sale' THEN f.qty ELSE 0 END) AS qty_sold,
            -SUM(CASE WHEN f.kind = 'return' THEN f.qty ELSE 0 END) AS qty_returned,
            SUM(f.gross) AS gross,
            SUM(f.discount) AS discount,
            SUM(CASE WHEN f.kind = 'sale' THEN f.amount ELSE 0 END) AS sales,
            -SUM(CASE WHEN f.kind = 'return' THEN f.amount ELSE 0 END) AS returns,
            SUM(f.amount) AS net,
            SUM(f.tax) AS tax,
            SUM(f.cost) AS cost";
    }

    /**
     * @return list<string>
     */
    public static function soldValues(): array
    {
        return array_map(fn (SaleStatus $status) => $status->value, self::SOLD);
    }
}
