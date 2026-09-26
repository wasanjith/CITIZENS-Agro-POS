<?php

namespace App\Domain\Reports\Reports\LossPrevention;

use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Models\CounterEvent;
use App\Models\User;

/**
 * Each removed item, cleared bill, reprint and void recorded by the counters (Live Billing's red lines).
 */
class CounterEventsReport extends Report
{
    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'counter-events';
    }

    public function title(): string
    {
        return 'Removed items & cleared bills (detail)';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::LossPrevention;
    }

    public function description(): string
    {
        return 'Every item removed, bill cleared, invoice reprinted or voided at a counter, one line each.';
    }

    public function permission(): string
    {
        return 'reports.sales';
    }

    public function defaultPeriod(): array
    {
        return [today()->subDays(6), today()];
    }

    public function filters(User $user): array
    {
        $types = collect(CounterEventType::cases())
            ->filter(fn (CounterEventType $type) => $type->isAlert())
            ->mapWithKeys(fn (CounterEventType $type) => [$type->value => ucfirst($type->label())])
            ->all();

        return [
            Filter::select('type', 'Event', $types),
            Filter::select('terminal', 'Counter', $this->lookups->terminals()),
        ];
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::dateTime('at', 'Time'),
            Column::text('counter', 'Counter'),
            Column::text('staff', 'Staff'),
            Column::text('event', 'Event'),
            Column::text('detail', 'Detail'),
            Column::money('value', 'Value'),
            Column::text('invoice', 'Invoice'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $alertTypes = collect(CounterEventType::cases())->filter(fn (CounterEventType $type) => $type->isAlert())->map->value->all();

        $rows = CounterEvent::query()
            ->whereIn('type', $input->get('type') !== null ? [$input->get('type')] : $alertTypes)
            ->where('created_at', '>=', $input->from)
            ->where('created_at', '<', $input->end())
            ->when($input->get('terminal'), fn ($query, $terminal) => $query->where('terminal_id', (int) $terminal))
            ->orderBy('created_at')
            ->limit(5000)
            ->get()
            ->map(function (CounterEvent $event): array {
                $payload = $event->payload ?? [];

                return [
                    'at' => $event->created_at,
                    'counter' => $this->lookups->terminal($event->terminal_id),
                    'staff' => $this->lookups->user($event->user_id),
                    'event' => ucfirst($event->type->label()),
                    'detail' => match ($event->type) {
                        CounterEventType::ItemRemoved => trim(($payload['name'] ?? '').' × '.($payload['qty'] ?? '').' '.($payload['unit'] ?? '')),
                        CounterEventType::CartCleared => ($payload['lines'] ?? 0).' lines',
                        default => $payload['reason'] ?? '',
                    },
                    'value' => $payload['line_total'] ?? $payload['total'] ?? null,
                    'invoice' => $payload['invoice_no'] ?? '',
                    '_url' => $event->sale_id !== null ? route('sales.show', $event->sale_id) : null,
                ];
            })
            ->all();

        return new ReportResult($rows, notes: ['Counter events are kept for 90 days.']);
    }
}
