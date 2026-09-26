<?php

namespace App\Domain\Reports\Reports\LossPrevention;

use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Enums\ApprovalStatus;
use App\Domain\Sales\Enums\ApprovalType;
use App\Domain\Sales\Models\ApprovalRequest;
use App\Models\User;

/**
 * Discounts above the staff limit and price changes: every request a counter sent to the cashier.
 */
class DiscountApprovalsReport extends Report
{
    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'discount-approvals';
    }

    public function title(): string
    {
        return 'Discounts above limit';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::LossPrevention;
    }

    public function description(): string
    {
        return 'Discounts over the staff limit and price changes that a counter asked the cashier to approve, and the answer.';
    }

    public function permission(): string
    {
        return 'reports.sales';
    }

    public function filters(User $user): array
    {
        return [
            Filter::select('status', 'Answer', collect(ApprovalStatus::cases())->mapWithKeys(fn (ApprovalStatus $status) => [$status->value => $status->label()])->all()),
            Filter::select('terminal', 'Counter', $this->lookups->terminals()),
        ];
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::dateTime('at', 'Asked'),
            Column::text('counter', 'Counter'),
            Column::text('staff', 'Asked by'),
            Column::text('type', 'Type'),
            Column::text('item', 'Item / bill'),
            Column::money('gross', 'Amount before', false),
            Column::money('amount', 'Discount'),
            Column::percent('percent', 'Discount %'),
            Column::text('status', 'Answer'),
            Column::text('decided_by', 'Answered by'),
            Column::text('note', 'Note'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $rows = ApprovalRequest::query()
            ->whereIn('type', [ApprovalType::Discount, ApprovalType::PriceOverride])
            ->where('created_at', '>=', $input->from)
            ->where('created_at', '<', $input->end())
            ->when($input->get('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($input->get('terminal'), fn ($query, $terminal) => $query->where('terminal_id', (int) $terminal))
            ->orderBy('created_at')
            ->get()
            ->map(fn (ApprovalRequest $request) => [
                'at' => $request->created_at,
                'counter' => $this->lookups->terminal($request->terminal_id),
                'staff' => $this->lookups->user($request->requested_by),
                'type' => $request->type->label(),
                'item' => ($request->payload['scope'] ?? '') === 'bill' ? 'Whole bill' : ($request->payload['label'] ?? ''),
                'gross' => $request->payload['gross'] ?? null,
                'amount' => $request->payload['amount'] ?? null,
                'percent' => $request->payload['percent'] ?? null,
                'status' => $request->status->label(),
                'decided_by' => $this->lookups->user($request->decided_by),
                'note' => $request->decision_note ?? '',
            ])
            ->all();

        return new ReportResult($rows);
    }
}
