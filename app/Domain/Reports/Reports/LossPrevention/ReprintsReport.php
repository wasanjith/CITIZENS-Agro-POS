<?php

namespace App\Domain\Reports\Reports\LossPrevention;

use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Models\PrintJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every COPY printed (from the print log).
 */
class ReprintsReport extends Report
{
    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'reprints';
    }

    public function title(): string
    {
        return 'Reprints';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::LossPrevention;
    }

    public function description(): string
    {
        return 'Every invoice, receipt or credit bill printed again (marked COPY), with who printed it and where.';
    }

    public function permission(): string
    {
        return 'reports.sales';
    }

    public function filters(User $user): array
    {
        return [
            Filter::select('document', 'Document', PrintDocumentType::options()),
            Filter::select('terminal', 'Terminal', $this->lookups->terminals()),
        ];
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::dateTime('at', 'Time'),
            Column::text('document', 'Document'),
            Column::text('number', 'Number'),
            Column::text('terminal', 'Terminal'),
            Column::text('user', 'Printed by'),
            Column::money('total', 'Invoice total', false),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $jobs = PrintJob::query()
            ->where('is_copy', true)
            ->where('created_at', '>=', $input->from)
            ->where('created_at', '<', $input->end())
            ->when($input->get('document'), fn ($query, $type) => $query->where('document_type', $type))
            ->when($input->get('terminal'), fn ($query, $terminal) => $query->where('terminal_id', (int) $terminal))
            ->orderBy('created_at')
            ->limit(5000)
            ->get();

        $saleTypes = [PrintDocumentType::Invoice, PrintDocumentType::CreditBill];
        $sales = DB::table('sales')
            ->whereIn('id', $jobs->filter(fn (PrintJob $job) => in_array($job->document_type, $saleTypes, true))->pluck('document_id'))
            ->get(['id', 'invoice_no', 'total'])
            ->keyBy('id');

        $rows = $jobs->map(function (PrintJob $job) use ($sales, $saleTypes): array {
            $sale = in_array($job->document_type, $saleTypes, true) ? $sales->get($job->document_id) : null;

            return [
                'at' => $job->created_at,
                'document' => $job->document_type->label(),
                'number' => $sale->invoice_no ?? ($job->document_id !== null ? '#'.$job->document_id : ''),
                'terminal' => $this->lookups->terminal($job->terminal_id),
                'user' => $this->lookups->user($job->user_id),
                'total' => $sale->total ?? null,
                '_url' => $sale !== null ? route('sales.show', $sale->id) : null,
            ];
        })->all();

        return new ReportResult($rows, ['Copies printed' => number_format(count($rows))]);
    }
}
