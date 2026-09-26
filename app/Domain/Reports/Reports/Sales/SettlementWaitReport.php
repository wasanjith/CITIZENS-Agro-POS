<?php

namespace App\Domain\Reports\Reports\Sales;

use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * How long printed invoices waited at the main cashier before they were settled, per counter.
 */
class SettlementWaitReport extends Report
{
    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'settlement-wait';
    }

    public function title(): string
    {
        return 'Settlement waiting time';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Sales;
    }

    public function description(): string
    {
        return 'Average and longest wait between printing an invoice at a counter and settling it, per counter.';
    }

    public function permission(): string
    {
        return 'reports.sales';
    }

    public function filters(User $user): array
    {
        return [Filter::number('slow', 'Slow after (minutes)', 10)];
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::text('counter', 'Counter'),
            Column::int('invoices', 'Invoices settled'),
            Column::number('average', 'Average wait (min)'),
            Column::number('longest', 'Longest wait (min)'),
            Column::int('slow', 'Waited over '.$input->int('slow', 10).' min'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $slowSeconds = max(1, $input->int('slow', 10)) * 60;

        $rows = DB::table('sales')
            ->whereNotNull('settled_at')
            ->whereNotNull('invoiced_at')
            ->where('settled_at', '>=', $input->from)
            ->where('settled_at', '<', $input->end())
            ->groupBy('invoiced_terminal_id')
            ->selectRaw('invoiced_terminal_id, COUNT(*) AS invoices,
                AVG(TIMESTAMPDIFF(SECOND, invoiced_at, settled_at)) / 60 AS average,
                MAX(TIMESTAMPDIFF(SECOND, invoiced_at, settled_at)) / 60 AS longest,
                SUM(TIMESTAMPDIFF(SECOND, invoiced_at, settled_at) > ?) AS slow', [$slowSeconds])
            ->get()
            ->map(fn (object $row) => [
                'counter' => $this->lookups->terminal((int) $row->invoiced_terminal_id),
                'invoices' => (int) $row->invoices,
                'average' => round((float) $row->average, 1),
                'longest' => round((float) $row->longest, 1),
                'slow' => (int) $row->slow,
                '_alert' => (float) $row->average * 60 > $slowSeconds,
            ])
            ->sortBy('counter')
            ->values()
            ->all();

        return new ReportResult($rows, notes: ['Includes invoices that were settled and later voided or returned.']);
    }
}
