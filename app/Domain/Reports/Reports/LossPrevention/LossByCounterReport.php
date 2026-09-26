<?php

namespace App\Domain\Reports\Reports\LossPrevention;

use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Enums\ApprovalType;
use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Enums\PrintDocumentType;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Removed items, cleared bills, reprints, voids and discount requests per counter and
 * staff member: the things that can hide a sale.
 */
class LossByCounterReport extends Report
{
    /** @var array<string, array<string, string|int>> */
    private array $rows = [];

    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'loss-by-counter';
    }

    public function title(): string
    {
        return 'Removed items & voids by counter';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::LossPrevention;
    }

    public function description(): string
    {
        return 'Items removed from bills, cleared bills, reprints, voids and discount requests per counter and staff member.';
    }

    public function permission(): string
    {
        return 'reports.sales';
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::text('counter', 'Counter'),
            Column::text('staff', 'Staff'),
            Column::int('removed', 'Items removed'),
            Column::money('removed_value', 'Removed value'),
            Column::int('cleared', 'Bills cleared'),
            Column::money('cleared_value', 'Cleared value'),
            Column::int('reprints', 'Reprints'),
            Column::int('voids', 'Voids'),
            Column::money('void_value', 'Voided value'),
            Column::int('discount_requests', 'Discount requests'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $range = [$input->from, $input->end()];
        $this->rows = [];
        $add = $this->add(...);

        $events = DB::table('counter_events')
            ->whereIn('type', [CounterEventType::ItemRemoved->value, CounterEventType::CartCleared->value])
            ->where('created_at', '>=', $range[0])
            ->where('created_at', '<', $range[1])
            ->groupBy('terminal_id', 'user_id', 'type')
            ->selectRaw("terminal_id, user_id, type, COUNT(*) AS events,
                SUM(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.line_total')) AS DECIMAL(15,2)), CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.total')) AS DECIMAL(15,2)), 0)) AS value")
            ->get();

        foreach ($events as $event) {
            $removed = $event->type === CounterEventType::ItemRemoved->value;
            $add((int) $event->terminal_id, $event->user_id !== null ? (int) $event->user_id : null, $removed ? 'removed' : 'cleared', $event->events, $removed ? 'removed_value' : 'cleared_value', (string) $event->value);
        }

        $reprints = DB::table('print_jobs')
            ->where('is_copy', true)
            ->where('document_type', PrintDocumentType::Invoice->value)
            ->whereNotNull('terminal_id')
            ->where('created_at', '>=', $range[0])
            ->where('created_at', '<', $range[1])
            ->groupBy('terminal_id', 'user_id')
            ->selectRaw('terminal_id, user_id, COUNT(*) AS reprints')
            ->get();

        foreach ($reprints as $reprint) {
            $add((int) $reprint->terminal_id, $reprint->user_id !== null ? (int) $reprint->user_id : null, 'reprints', $reprint->reprints);
        }

        $voids = DB::table('sales')
            ->where('voided_at', '>=', $range[0])
            ->where('voided_at', '<', $range[1])
            ->groupBy('invoiced_terminal_id', 'invoiced_by')
            ->selectRaw('invoiced_terminal_id, invoiced_by, COUNT(*) AS voids, SUM(total) AS value')
            ->get();

        foreach ($voids as $void) {
            $add((int) $void->invoiced_terminal_id, (int) $void->invoiced_by, 'voids', $void->voids, 'void_value', (string) $void->value);
        }

        $requests = DB::table('approval_requests')
            ->whereIn('type', [ApprovalType::Discount->value, ApprovalType::PriceOverride->value])
            ->where('created_at', '>=', $range[0])
            ->where('created_at', '<', $range[1])
            ->groupBy('terminal_id', 'requested_by')
            ->selectRaw('terminal_id, requested_by, COUNT(*) AS requests')
            ->get();

        foreach ($requests as $request) {
            $add((int) $request->terminal_id, (int) $request->requested_by, 'discount_requests', $request->requests);
        }

        ksort($this->rows);

        return new ReportResult(array_values($this->rows), notes: [
            'A void counts against the counter and staff member who printed the invoice.',
            'Counter events are kept for 90 days, so older removed items and cleared bills are not shown.',
        ]);
    }

    private function add(int $terminalId, ?int $userId, string $key, string|int $count, ?string $valueKey = null, ?string $value = null): void
    {
        $index = $terminalId.'|'.($userId ?? 0);
        $this->rows[$index] ??= [
            'counter' => $this->lookups->terminal($terminalId),
            'staff' => $this->lookups->user($userId),
            'removed' => 0, 'removed_value' => '0.00', 'cleared' => 0, 'cleared_value' => '0.00',
            'reprints' => 0, 'voids' => 0, 'void_value' => '0.00', 'discount_requests' => 0,
        ];
        $this->rows[$index][$key] = (int) $this->rows[$index][$key] + (int) $count;

        if ($valueKey !== null) {
            $this->rows[$index][$valueKey] = (string) BigDecimal::of((string) $this->rows[$index][$valueKey])->plus($value ?? '0')->toScale(2);
        }
    }
}
