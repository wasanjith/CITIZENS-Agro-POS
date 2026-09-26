<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Reports\Audit\PriceChangesReport;
use App\Domain\Reports\Reports\Audit\UserLoginsReport;
use App\Domain\Reports\Reports\Customers\CreditSalesReport;
use App\Domain\Reports\Reports\Customers\ReceivablesAgeingReport;
use App\Domain\Reports\Reports\Finance\DrawerSessionsReport;
use App\Domain\Reports\Reports\Finance\ExpensesReport;
use App\Domain\Reports\Reports\Finance\HandoverHistoryReport;
use App\Domain\Reports\Reports\Inventory\DeadStockReport;
use App\Domain\Reports\Reports\Inventory\ExpiryReport;
use App\Domain\Reports\Reports\Inventory\MovementHistoryReport;
use App\Domain\Reports\Reports\Inventory\ReorderListReport;
use App\Domain\Reports\Reports\Inventory\StockValuationReport;
use App\Domain\Reports\Reports\Inventory\StockVarianceReport;
use App\Domain\Reports\Reports\LossPrevention\CounterEventsReport;
use App\Domain\Reports\Reports\LossPrevention\DiscountApprovalsReport;
use App\Domain\Reports\Reports\LossPrevention\LossByCounterReport;
use App\Domain\Reports\Reports\LossPrevention\ReprintsReport;
use App\Domain\Reports\Reports\Purchasing\OpenPurchaseOrdersReport;
use App\Domain\Reports\Reports\Purchasing\PurchasesReport;
use App\Domain\Reports\Reports\Purchasing\SupplierAgeingReport;
use App\Domain\Reports\Reports\Sales\DailySalesReport;
use App\Domain\Reports\Reports\Sales\DiscountsReport;
use App\Domain\Reports\Reports\Sales\SalesBreakdownReport;
use App\Domain\Reports\Reports\Sales\SettlementWaitReport;
use App\Domain\Reports\Support\ReportGroup;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Every report of the Reports page, plus links to report pages that live elsewhere
 * (financial statements, HR reports, audit log).
 */
class ReportRegistry
{
    /** @var array<string, Report>|null */
    private ?array $reports = null;

    /**
     * @return array<string, Report> key => report, in menu order
     */
    public function all(): array
    {
        if ($this->reports !== null) {
            return $this->reports;
        }

        $breakdown = fn (string $dimension, bool $profit = false) => app()->make(SalesBreakdownReport::class, ['dimension' => $dimension, 'profit' => $profit]);

        $reports = [
            app(DailySalesReport::class),
            ...array_map(fn (string $dimension) => $breakdown($dimension), ['item', 'category', 'brand', 'counter', 'staff', 'customer', 'hour']),
            app(DiscountsReport::class),
            app(SettlementWaitReport::class),

            app(LossByCounterReport::class),
            app(CounterEventsReport::class),
            app(ReprintsReport::class),
            app(DiscountApprovalsReport::class),

            $breakdown('item', true),
            $breakdown('category', true),
            $breakdown('day', true),

            app()->make(StockValuationReport::class, ['byBatch' => false]),
            app()->make(StockValuationReport::class, ['byBatch' => true]),
            app(MovementHistoryReport::class),
            app(ExpiryReport::class),
            app(DeadStockReport::class),
            app(ReorderListReport::class),
            app(StockVarianceReport::class),

            app()->make(PurchasesReport::class, ['byItem' => false]),
            app()->make(PurchasesReport::class, ['byItem' => true]),
            app(OpenPurchaseOrdersReport::class),
            app(SupplierAgeingReport::class),

            app(ReceivablesAgeingReport::class),
            app(CreditSalesReport::class),

            app(DrawerSessionsReport::class),
            app(HandoverHistoryReport::class),
            app(ExpensesReport::class),

            app(PriceChangesReport::class),
            app(UserLoginsReport::class),
        ];

        return $this->reports = collect($reports)->keyBy(fn (Report $report) => $report->key())->all();
    }

    public function find(string $key): ?Report
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Report pages outside the generic report screen.
     *
     * @return list<array{group: ReportGroup, title: string, description: string, route: string, can: list<string>}>
     */
    public function links(): array
    {
        return [
            ['group' => ReportGroup::Customers, 'title' => 'Customer statement', 'description' => 'Open a customer and choose Statement (PDF) for any period.', 'route' => 'customers.index', 'can' => ['customers.view']],
            ['group' => ReportGroup::Finance, 'title' => 'Profit & loss', 'description' => 'Sales, cost of sales, expenses and net profit from the books.', 'route' => 'finance.reports.profit-loss', 'can' => ['reports.finance']],
            ['group' => ReportGroup::Finance, 'title' => 'Trial balance', 'description' => 'Balance of every account.', 'route' => 'finance.reports.trial-balance', 'can' => ['reports.finance']],
            ['group' => ReportGroup::Finance, 'title' => 'Balance sheet', 'description' => 'What the shop owns and owes.', 'route' => 'finance.reports.balance-sheet', 'can' => ['reports.finance']],
            ['group' => ReportGroup::Finance, 'title' => 'Cash book', 'description' => 'Cash at home and the drawer, day by day.', 'route' => 'finance.reports.cash-book', 'can' => ['reports.finance']],
            ['group' => ReportGroup::Finance, 'title' => 'Bank book', 'description' => 'Open a bank account for its book and reconciliation.', 'route' => 'finance.bank-accounts.index', 'can' => ['finance.banks.manage']],
            ['group' => ReportGroup::Finance, 'title' => 'Cheque register', 'description' => 'Cheques received and issued, by status.', 'route' => 'finance.cheques.index', 'can' => ['finance.cheques.manage']],
            ['group' => ReportGroup::Hr, 'title' => 'Attendance summary', 'description' => 'Days worked, leave, no-pay, late and overtime per employee.', 'route' => 'hr.reports.attendance', 'can' => ['reports.hr']],
            ['group' => ReportGroup::Hr, 'title' => 'Payroll summary', 'description' => 'Salaries, EPF / ETF and advances for a year.', 'route' => 'hr.reports.payroll', 'can' => ['reports.hr']],
            ['group' => ReportGroup::Hr, 'title' => 'EPF / ETF', 'description' => 'Contributions for a month.', 'route' => 'hr.reports.epf-etf', 'can' => ['reports.hr']],
            ['group' => ReportGroup::Hr, 'title' => 'Leave', 'description' => 'Leave requests and balances.', 'route' => 'hr.leave.index', 'can' => ['hr.attendance.view', 'hr.employees.manage']],
            ['group' => ReportGroup::Audit, 'title' => 'Activity log', 'description' => 'Every change to prices, customers, stock, money and settings.', 'route' => 'admin.audit.index', 'can' => ['admin.audit.view']],
            ['group' => ReportGroup::Audit, 'title' => 'Print log', 'description' => 'Every print and reprint on the thermal printers.', 'route' => 'admin.print-jobs.index', 'can' => ['admin.terminals.manage']],
        ];
    }

    /**
     * Reports and links the user may open, per group (groups with nothing are left out).
     *
     * @return Collection<string, array{group: ReportGroup, reports: list<Report>, links: list<array{group: ReportGroup, title: string, description: string, route: string, can: list<string>}>}>
     */
    public function menuFor(User $user): Collection
    {
        return collect(ReportGroup::cases())
            ->mapWithKeys(fn (ReportGroup $group) => [$group->value => [
                'group' => $group,
                'reports' => array_values(array_filter($this->all(), fn (Report $report) => $report->group() === $group && $report->canBeViewedBy($user))),
                'links' => array_values(array_filter($this->links(), fn (array $link) => $link['group'] === $group && $user->canAny($link['can']))),
            ]])
            ->filter(fn (array $section) => $section['reports'] !== [] || $section['links'] !== []);
    }

    public function anyFor(User $user): bool
    {
        return $this->menuFor($user)->isNotEmpty();
    }
}
