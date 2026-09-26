<?php

namespace App\Domain\Reports\Reports\Finance;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;

/**
 * Every drawer session opened in the period: who held it, float, expected and counted cash, variance.
 */
class DrawerSessionsReport extends Report
{
    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'drawer-sessions';
    }

    public function title(): string
    {
        return 'Drawer sessions & variances';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Finance;
    }

    public function description(): string
    {
        return 'Each cash drawer session with its float, expected and counted cash and the shortage or excess.';
    }

    public function permission(): array
    {
        return ['reports.sales', 'drawer.handover'];
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::dateTime('opened_at', 'Opened'),
            Column::dateTime('closed_at', 'Closed'),
            Column::text('terminal', 'Terminal'),
            Column::text('holder', 'Held by'),
            Column::money('float', 'Float'),
            Column::money('expected', 'Expected'),
            Column::money('counted', 'Counted'),
            Column::money('variance', 'Variance'),
            Column::text('reason', 'Closed for'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $rows = DrawerSession::query()
            ->where('opened_at', '>=', $input->from)
            ->where('opened_at', '<', $input->end())
            ->orderBy('opened_at')
            ->get()
            ->map(fn (DrawerSession $session) => [
                'opened_at' => $session->opened_at,
                'closed_at' => $session->closed_at,
                'terminal' => $this->lookups->terminal($session->terminal_id),
                'holder' => $this->lookups->user($session->holder_user_id),
                'float' => $session->opening_float,
                'expected' => $session->expected_cash,
                'counted' => $session->counted_cash,
                'variance' => $session->variance,
                'reason' => $session->closed_at === null ? 'Still open' : ($session->close_reason?->label() ?? ''),
                '_url' => route('pos.drawer.report', $session->id),
                '_alert' => $session->variance !== null && ! Money::of($session->variance)->isZero(),
            ]);

        $short = $rows->reduce(fn (BigDecimal $sum, array $row) => $row['variance'] !== null && BigDecimal::of($row['variance'])->isNegative() ? $sum->minus($row['variance']) : $sum, Money::zero());
        $over = $rows->reduce(fn (BigDecimal $sum, array $row) => $row['variance'] !== null && BigDecimal::of($row['variance'])->isPositive() ? $sum->plus($row['variance']) : $sum, Money::zero());

        return new ReportResult($rows->all(), ['Sessions' => number_format($rows->count()), 'Cash short' => Money::format($short), 'Cash over' => Money::format($over)]);
    }
}
