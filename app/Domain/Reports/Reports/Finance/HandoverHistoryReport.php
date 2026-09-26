<?php

namespace App\Domain\Reports\Reports\Finance;

use App\Domain\Identity\Models\Delegation;
use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;

/**
 * Every time the owner handed cashier authority to someone, how long for and how it ended.
 */
class HandoverHistoryReport extends Report
{
    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'handover-history';
    }

    public function title(): string
    {
        return 'Handover history';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Finance;
    }

    public function description(): string
    {
        return 'Cashier authority handed over: to whom, when, until when and how it ended.';
    }

    public function permission(): string
    {
        return 'drawer.handover';
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::dateTime('starts_at', 'Started'),
            Column::text('from', 'From'),
            Column::text('to', 'To'),
            Column::dateTime('expires_at', 'Expires'),
            Column::dateTime('ended_at', 'Ended'),
            Column::text('how', 'How it ended'),
            Column::int('permissions', 'Permissions', false),
            Column::text('reason', 'Reason'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $rows = Delegation::query()
            ->where('starts_at', '>=', $input->from)
            ->where('starts_at', '<', $input->end())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Delegation $delegation) => [
                'starts_at' => $delegation->starts_at,
                'from' => $this->lookups->user($delegation->from_user_id),
                'to' => $this->lookups->user($delegation->to_user_id),
                'expires_at' => $delegation->expires_at,
                'ended_at' => $delegation->revoked_at ?? ($delegation->expires_at->isPast() ? $delegation->expires_at : null),
                'how' => match (true) {
                    $delegation->revoked_at !== null => 'Ended by '.$this->lookups->user($delegation->revoked_by),
                    $delegation->expires_at->isPast() => 'Expired',
                    default => 'Still active',
                },
                'permissions' => count($delegation->permissions ?? []),
                'reason' => $delegation->reason ?? '',
            ])
            ->all();

        return new ReportResult($rows, notes: ['Cash counted at each handover is on the drawer sessions report (closed for "Handover").']);
    }
}
