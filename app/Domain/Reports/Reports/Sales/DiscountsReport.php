<?php

namespace App\Domain\Reports\Reports\Sales;

use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Services\SalesFacts;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Brick\Math\BigDecimal;

/**
 * Every settled invoice that had a line or bill discount.
 */
class DiscountsReport extends Report
{
    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'discounts';
    }

    public function title(): string
    {
        return 'Discounts given';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Sales;
    }

    public function description(): string
    {
        return 'Settled invoices with a line or bill discount, by counter and staff member.';
    }

    public function permission(): string
    {
        return 'reports.sales';
    }

    public function filters(User $user): array
    {
        return [
            Filter::select('terminal', 'Counter', $this->lookups->terminals()),
            Filter::select('user', 'Staff', $this->lookups->users()),
        ];
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::dateTime('settled_at', 'Settled'),
            Column::text('invoice_no', 'Invoice'),
            Column::text('counter', 'Counter'),
            Column::text('staff', 'Staff'),
            Column::money('subtotal', 'Before discounts'),
            Column::money('line_discount', 'Line discounts'),
            Column::money('bill_discount', 'Bill discount'),
            Column::money('discount', 'Total discount'),
            Column::percent('percent', 'Discount'),
            Column::money('total', 'Invoice total'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $sales = Sale::query()
            ->whereIn('status', SalesFacts::SOLD)
            ->where('settled_at', '>=', $input->from)
            ->where('settled_at', '<', $input->end())
            ->whereRaw('line_discount_total + bill_discount > 0')
            ->when($input->get('terminal'), fn ($query, $terminal) => $query->where('invoiced_terminal_id', (int) $terminal))
            ->when($input->get('user'), fn ($query, $user) => $query->where('invoiced_by', (int) $user))
            ->orderBy('settled_at')
            ->get(['id', 'invoice_no', 'settled_at', 'invoiced_terminal_id', 'invoiced_by', 'subtotal', 'line_discount_total', 'bill_discount', 'total']);

        $rows = $sales->map(function (Sale $sale): array {
            $discount = Money::of($sale->line_discount_total)->plus($sale->bill_discount);

            return [
                'settled_at' => $sale->settled_at,
                'invoice_no' => $sale->invoice_no,
                'counter' => $this->lookups->terminal($sale->invoiced_terminal_id),
                'staff' => $this->lookups->user($sale->invoiced_by),
                'subtotal' => $sale->subtotal,
                'line_discount' => $sale->line_discount_total,
                'bill_discount' => $sale->bill_discount,
                'discount' => (string) $discount,
                'percent' => (string) Money::percent($discount, Money::of($sale->subtotal)),
                'total' => $sale->total,
                '_url' => route('sales.show', $sale->id),
            ];
        })->all();

        $total = collect($rows)->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['discount']), Money::zero());

        return new ReportResult($rows, ['Invoices with a discount' => number_format(count($rows)), 'Discounts given' => Money::format($total)]);
    }
}
