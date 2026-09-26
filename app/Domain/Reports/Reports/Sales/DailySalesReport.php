<?php

namespace App\Domain\Reports\Reports\Sales;

use App\Domain\Reports\Report;
use App\Domain\Reports\Services\DailySalesFigures;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Daily summary / Z report: one line per day with sales, returns, voids and the money
 * taken by payment method.
 */
class DailySalesReport extends Report
{
    public function __construct(private readonly DailySalesFigures $figures, private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'daily-sales';
    }

    public function title(): string
    {
        return 'Daily summary (Z report)';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Sales;
    }

    public function description(): string
    {
        return 'Sales, returns, voids and money taken per payment method, day by day.';
    }

    public function permission(): string
    {
        return 'reports.sales';
    }

    public function filters(User $user): array
    {
        return [Filter::select('terminal', 'Counter', $this->lookups->terminals())];
    }

    public function columns(ReportInput $input): array
    {
        $profit = $input->user->can('reports.profit');

        return array_values(array_filter([
            Column::date('date', 'Date'),
            Column::int('invoices', 'Invoices'),
            Column::money('gross', 'Gross'),
            Column::money('discount', 'Discounts'),
            Column::money('sales', 'Sales'),
            Column::money('returns', 'Returns'),
            Column::money('net', 'Net sales'),
            $profit ? Column::money('cost', 'Cost') : null,
            $profit ? Column::money('profit', 'Gross profit') : null,
            ...collect(PaymentMethod::counterMethods())->map(fn (PaymentMethod $method) => Column::money('pay_'.$method->value, $method->label()))->all(),
            Column::int('voids', 'Voids'),
            Column::money('void_value', 'Voided value'),
        ]));
    }

    public function run(ReportInput $input): ReportResult
    {
        $terminal = $input->get('terminal');
        $days = $this->figures->between($input->from, $input->to)
            ->when($terminal !== null, fn ($rows) => $rows->where('terminal_id', (int) $terminal))
            ->groupBy('date');

        $rows = $days->map(function ($perCounter, string $date) {
            $row = ['date' => $date];

            foreach ([...DailySalesFigures::COUNTS, ...DailySalesFigures::AMOUNTS] as $key) {
                $row[$key] = (string) $perCounter->reduce(fn (BigDecimal $sum, array $counter) => $sum->plus($counter[$key]), BigDecimal::zero());
            }

            $row['profit'] = (string) Money::of($row['net'])->minus($row['tax'])->minus($row['cost']);

            return $row;
        })->sortKeys()->values()->all();

        $net = collect($rows)->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['net']), Money::zero());
        $invoices = (int) collect($rows)->sum(fn (array $row) => (int) $row['invoices']);
        $collected = $terminal === null
            ? (string) DB::table('customer_payments')->whereNull('reversed_at')->whereBetween('date', [$input->from->toDateString(), $input->to->toDateString()])->sum('amount')
            : null;

        $notes = ['A sale counts on the day it was settled; a return on the day it was taken. The "Credit" column is sold on credit (not cash in hand).'];

        if ($this->figures->usesSummaries($input->from, $input->to)) {
            $notes[] = 'Past days come from the nightly summary (rebuilt at 02:50 for the last 40 days).';
        }

        return new ReportResult($rows, array_filter([
            'Net sales' => Money::format($net),
            'Invoices' => number_format($invoices),
            'Average bill' => $invoices > 0 ? Money::format($net->dividedBy($invoices, 2, RoundingMode::HalfUp)) : '0.00',
            'Credit payments received' => $collected !== null ? Money::format($collected) : null,
        ]), $notes);
    }
}
