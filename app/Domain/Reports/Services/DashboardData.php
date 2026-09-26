<?php

namespace App\Domain\Reports\Services;

use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Catalog\Models\Product;
use App\Domain\Finance\Enums\ChequeDirection;
use App\Domain\Finance\Enums\ChequeStatus;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Identity\Models\Delegation;
use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Inventory\Services\StockAlerts;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Sales\Enums\ApprovalStatus;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the owner / manager dashboard. Each part is only worked out when the user
 * may see it, so a Manager's dashboard never queries money they may not see.
 */
class DashboardData
{
    public function __construct(
        private readonly DailySalesFigures $figures,
        private readonly SalesFacts $facts,
        private readonly ReportLookups $lookups,
        private readonly StockAlerts $alerts,
        private readonly CashierAuthority $authority,
        private readonly DrawerCalculator $drawer,
        private readonly ChartOfAccounts $accounts,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        return array_filter([
            'sales' => $user->can('reports.sales') ? $this->todaysSales() : null,
            'waiting' => $user->canAny(['pos.live_view', 'pos.settle']) ? $this->waiting() : null,
            'drawer' => $user->canAny(['drawer.handover', 'reports.finance']) ? $this->drawer() : null,
            'approvals' => $user->can('pos.approve_requests') ? DB::table('approval_requests')->where('status', ApprovalStatus::Pending->value)->count() : null,
            'purchasing' => $user->canAny(['purchasing.po.approve', 'purchasing.grn.create']) ? $this->purchasing() : null,
            'stock' => $user->can('inventory.view') ? $this->stock() : null,
            'cheques' => $user->can('finance.cheques.manage') ? $this->cheques() : null,
            'balances' => $user->can('reports.finance') ? $this->balances() : null,
            'topItems' => $user->can('reports.sales') ? $this->topItems() : null,
            'hours' => $user->can('reports.sales') ? $this->salesByHour() : null,
        ], fn ($part) => $part !== null);
    }

    /**
     * Today's settled sales (less returns) in total and per counter.
     *
     * @return array{net: string, invoices: int, returns: string, counters: list<array{name: string, invoices: int, net: string}>}
     */
    public function todaysSales(): array
    {
        $rows = $this->figures->live(today(), today());
        $sum = fn (string $key) => (string) $rows->reduce(fn (BigDecimal $total, array $row) => $total->plus($row[$key]), Money::zero());

        return [
            'net' => $sum('net'),
            'returns' => $sum('returns'),
            'invoices' => (int) $rows->sum(fn (array $row) => (int) $row['invoices']),
            'counters' => $rows->map(fn (array $row) => [
                'name' => $this->lookups->terminal($row['terminal_id']),
                'invoices' => (int) $row['invoices'],
                'net' => (string) Money::of($row['net']),
            ])->sortBy('name')->values()->all(),
        ];
    }

    /**
     * @return array{count: int, total: string, oldest_minutes: int|null}
     */
    public function waiting(): array
    {
        $row = DB::table('sales')->where('status', SaleStatus::Invoiced->value)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(total), 0) AS total, MIN(invoiced_at) AS oldest')
            ->first();

        return [
            'count' => (int) $row->count,
            'total' => (string) Money::of((string) $row->total),
            'oldest_minutes' => $row->oldest !== null ? (int) now()->parse($row->oldest)->diffInMinutes(now()) : null,
        ];
    }

    /**
     * @return array{holder: string|null, delegation: Delegation|null, cash: string|null}
     */
    public function drawer(): array
    {
        $session = $this->authority->openSession();

        return [
            'holder' => $this->authority->holder()?->name,
            'delegation' => $this->authority->activeDelegation(),
            'cash' => $session !== null ? (string) $this->drawer->expectedCash($session) : null,
        ];
    }

    /**
     * @return array{to_approve: int, to_receive: int, draft_receipts: int}
     */
    public function purchasing(): array
    {
        return [
            'to_approve' => DB::table('purchase_orders')->where('status', PurchaseOrderStatus::Submitted->value)->count(),
            'to_receive' => DB::table('purchase_orders')->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::Partial->value])->count(),
            'draft_receipts' => DB::table('goods_receipts')->where('status', GoodsReceiptStatus::Draft->value)->count(),
        ];
    }

    /**
     * @return array{low: int, expiring: int, expiry_days: int, low_items: list<array{id: int, code: string, name: string, on_hand: string, reorder_level: string}>}
     */
    public function stock(): array
    {
        $days = (int) $this->settings->get('inventory.expiry_alert_days', 30);

        return [
            'low' => $this->alerts->lowStockCount(),
            'expiring' => $this->alerts->expiringCount($days),
            'expiry_days' => $days,
            'low_items' => $this->alerts->whereLowStock(Product::query())
                ->addSelect(['products.id', 'products.short_code', 'products.name', 'products.reorder_level', 'on_hand' => StockAlerts::onHandSubquery()])
                ->orderByRaw('on_hand / products.reorder_level')
                ->limit(8)
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'code' => $product->short_code,
                    'name' => $product->name,
                    'on_hand' => (string) $product->getAttribute('on_hand'),
                    'reorder_level' => (string) $product->reorder_level,
                ])
                ->all(),
        ];
    }

    /**
     * @return array{to_deposit: int, to_deposit_total: string, issued_due: int, issued_due_total: string, days: int}
     */
    public function cheques(): array
    {
        $days = (int) $this->settings->get('finance.cheque_alert_days', 3);
        $received = Cheque::query()->where('direction', ChequeDirection::Received)->where('status', ChequeStatus::Pending)->whereDate('cheque_date', '<=', today());
        $issued = Cheque::query()->where('direction', ChequeDirection::Issued)->where('status', ChequeStatus::Pending)->whereDate('cheque_date', '<=', today()->addDays($days));

        return [
            'to_deposit' => (clone $received)->count(),
            'to_deposit_total' => (string) Money::of((string) (clone $received)->sum('amount')),
            'issued_due' => (clone $issued)->count(),
            'issued_due_total' => (string) Money::of((string) (clone $issued)->sum('amount')),
            'days' => $days,
        ];
    }

    /**
     * What customers owe the shop and what the shop owes suppliers (from the books).
     *
     * @return array{receivable: string, payable: string}
     */
    public function balances(): array
    {
        return [
            'receivable' => (string) $this->accounts->get(SystemAccount::AccountsReceivable)->balance(),
            'payable' => (string) $this->accounts->get(SystemAccount::AccountsPayable)->balance(),
        ];
    }

    /**
     * Best-selling items of the last 30 days by net sales.
     *
     * @return list<array{code: string, name: string, net: string}>
     */
    public function topItems(): array
    {
        $rows = DB::query()->fromSub($this->facts->lines(today()->subDays(29), today()->addDay()), 'f')
            ->groupBy('f.product_id')
            ->selectRaw('f.product_id, SUM(f.amount) AS net')
            ->orderByDesc('net')
            ->limit(10)
            ->get();
        $products = Product::withTrashed()->whereIn('id', $rows->pluck('product_id'))->get(['id', 'short_code', 'name'])->keyBy('id');

        return $rows->map(fn (object $row) => [
            'code' => $products[$row->product_id]->short_code ?? '',
            'name' => $products[$row->product_id]->name ?? '',
            'net' => (string) Money::of((string) $row->net),
        ])->all();
    }

    /**
     * Today's net sales per hour of the day, for the opening hours (06:00–20:00 at least).
     *
     * @return list<array{hour: int, net: string, invoices: int}>
     */
    public function salesByHour(): array
    {
        $rows = DB::query()->fromSub($this->facts->bills(today(), today()->addDay()), 'f')
            ->groupByRaw('HOUR(f.at)')
            ->selectRaw('HOUR(f.at) AS hour, SUM(f.amount) AS net, COUNT(DISTINCT CASE WHEN f.kind = \'sale\' THEN f.sale_id END) AS invoices')
            ->get()
            ->keyBy('hour');

        $first = min(6, (int) ($rows->keys()->min() ?? 6));
        $last = max(20, (int) ($rows->keys()->max() ?? 20));

        return collect(range($first, $last))->map(fn (int $hour) => [
            'hour' => $hour,
            'net' => (string) Money::of((string) ($rows[$hour]->net ?? '0')),
            'invoices' => (int) ($rows[$hour]->invoices ?? 0),
        ])->all();
    }
}
