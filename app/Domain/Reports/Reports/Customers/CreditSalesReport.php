<?php

namespace App\Domain\Reports\Reports\Customers;

use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Services\SalesFacts;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Brick\Math\BigDecimal;

/**
 * Invoices settled on credit in the period and what is still unpaid on each.
 */
class CreditSalesReport extends Report
{
    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'credit-sales';
    }

    public function title(): string
    {
        return 'Credit sales';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Customers;
    }

    public function description(): string
    {
        return 'Invoices sold on credit in the period, their due date and what is still unpaid.';
    }

    public function permission(): array
    {
        return ['customers.view', 'reports.sales'];
    }

    public function filters(User $user): array
    {
        return [Filter::checkbox('unpaid', 'Unpaid only')];
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::date('date', 'Date'),
            Column::text('invoice_no', 'Invoice'),
            Column::text('customer', 'Customer'),
            Column::money('total', 'Invoice total'),
            Column::money('paid', 'Paid / returned'),
            Column::money('balance', 'Unpaid'),
            Column::date('due_date', 'Due'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $sales = Sale::query()
            ->whereIn('status', SalesFacts::SOLD)
            ->where('payment_method_intent', PaymentMethod::Credit)
            ->where('settled_at', '>=', $input->from)
            ->where('settled_at', '<', $input->end())
            ->when($input->flag('unpaid'), fn ($query) => $query->where('balance_due', '>', 0))
            ->orderBy('settled_at')
            ->get(['id', 'invoice_no', 'status', 'payment_method_intent', 'settled_at', 'customer_id', 'total', 'balance_due', 'due_date']);
        $customers = $this->lookups->customers($sales->pluck('customer_id')->all());

        $rows = $sales->map(fn (Sale $sale) => [
            'date' => $sale->settled_at,
            'invoice_no' => $sale->invoice_no,
            'customer' => isset($customers[$sale->customer_id]) ? $customers[$sale->customer_id]->name.' ('.$customers[$sale->customer_id]->code.')' : '',
            'total' => $sale->total,
            'paid' => (string) Money::of($sale->total)->minus($sale->balance_due),
            'balance' => $sale->balance_due,
            'due_date' => $sale->due_date,
            '_url' => route('sales.show', $sale->id),
            '_alert' => $sale->isOverdue(),
        ])->all();

        $total = collect($rows)->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['total']), Money::zero());
        $unpaid = collect($rows)->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['balance']), Money::zero());

        return new ReportResult($rows, ['Sold on credit' => Money::format($total), 'Still unpaid' => Money::format($unpaid)], ['Red: overdue.']);
    }
}
