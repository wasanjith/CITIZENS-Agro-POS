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
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Sales\Enums\ApprovalStatus;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the owner / manager dashboard. Each part is only worked out when the user
 * may see it, so a Manager's dashboard never queries money they may not see.
 */
class DashboardData
{
    /**
     * Unpaid supplier bills turn "due soon" this many days before they are due.
     */
    public const DUE_SOON_DAYS = 7;

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
            'month' => $user->can('reports.sales') ? $this->monthToDate() : null,
            'profit' => $user->can('reports.profit') ? $this->profit() : null,
            'payments' => $user->can('reports.sales') ? $this->paymentsToday() : null,
            'waiting' => $user->canAny(['pos.live_view', 'pos.settle']) ? $this->waiting() : null,
            'drawer' => $user->canAny(['drawer.handover', 'reports.finance']) ? $this->drawer() : null,
            'approvals' => $user->can('pos.approve_requests') ? DB::table('approval_requests')->where('status', ApprovalStatus::Pending->value)->count() : null,
            'purchasing' => $user->canAny(['purchasing.po.approve', 'purchasing.grn.create']) ? $this->purchasing() : null,
            'stock' => $user->can('inventory.view') ? $this->stock() : null,
            'cheques' => $user->can('finance.cheques.manage') ? $this->cheques() : null,
            'balances' => $user->can('reports.finance') ? $this->balances() : null,
            'supplierDues' => $user->canAny(['purchasing.suppliers.pay', 'reports.finance']) ? $this->supplierDues() : null,
            'bestToday' => $user->can('reports.sales') ? $this->bestSellersToday() : null,
            'recentSales' => $user->can('reports.sales') ? $this->recentSales() : null,
            'hours' => $user->can('reports.sales') ? $this->salesByHour() : null,
        ], fn ($part) => $part !== null);
    }

    /**
     * Today's settled sales (less returns) in total and per counter, with yesterday's whole
     * day for comparison.
     *
     * @return array{net: string, invoices: int, returns: string, yesterday: string, change: string|null, counters: list<array{name: string, invoices: int, net: string}>}
     */
    public function todaysSales(): array
    {
        $rows = $this->figures->live(today(), today());
        $net = $this->total($rows, 'net');
        $yesterday = $this->total($this->figures->live(today()->subDay(), today()->subDay()), 'net');

        return [
            'net' => (string) $net,
            'returns' => (string) $this->total($rows, 'returns'),
            'invoices' => (int) $rows->sum(fn (array $row) => (int) $row['invoices']),
            'yesterday' => (string) $yesterday,
            'change' => $yesterday->isZero() ? null : (string) Money::percent($net->minus($yesterday), $yesterday),
            'counters' => $rows->map(fn (array $row) => [
                'name' => $this->lookups->terminal((int) $row['terminal_id']),
                'invoices' => (int) $row['invoices'],
                'net' => (string) Money::of((string) $row['net']),
            ])->sortBy('name')->values()->all(),
        ];
    }

    /**
     * Settled sales (less returns) from the 1st of this month to today.
     *
     * @return array{net: string, invoices: int, from: string}
     */
    public function monthToDate(): array
    {
        $rows = $this->figures->live(today()->startOfMonth(), today());

        return [
            'net' => (string) $this->total($rows, 'net'),
            'invoices' => (int) $rows->sum(fn (array $row) => (int) $row['invoices']),
            'from' => today()->startOfMonth()->toDateString(),
        ];
    }

    /**
     * Gross profit (net sales without tax, less the cost of what was sold) today and this
     * month, worked out like the "Gross profit by …" reports.
     *
     * @return array{today: array{profit: string, margin: string}, month: array{profit: string, margin: string}}
     */
    public function profit(): array
    {
        $rows = $this->figures->live(today()->startOfMonth(), today());
        $figure = function (Collection $rows): array {
            $net = $this->total($rows, 'net')->minus($this->total($rows, 'tax'));
            $profit = $net->minus($this->total($rows, 'cost'));

            return ['profit' => (string) $profit, 'margin' => (string) Money::percent($profit, $net)];
        };

        return [
            'today' => $figure($rows->where('date', today()->toDateString())),
            'month' => $figure($rows),
        ];
    }

    /**
     * Money taken today per payment method (cash refunds are taken off cash). Cash, card and
     * bank transfer are always listed; cheque and credit only when used.
     *
     * @return array{methods: list<array{method: string, label: string, amount: string}>, total: string}
     */
    public function paymentsToday(): array
    {
        $rows = $this->figures->live(today(), today());
        $always = [PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::BankTransfer];
        $methods = collect([...$always, PaymentMethod::Cheque, PaymentMethod::Credit])
            ->map(fn (PaymentMethod $method) => ['method' => $method, 'amount' => $this->total($rows, 'pay_'.$method->value)])
            ->filter(fn (array $row) => in_array($row['method'], $always, true) || ! $row['amount']->isZero())
            ->values();

        return [
            'methods' => $methods->map(fn (array $row) => [
                'method' => $row['method']->value,
                'label' => $row['method']->label(),
                'amount' => (string) $row['amount'],
            ])->all(),
            'total' => (string) $methods->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['amount']), Money::zero()),
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
     * The main drawer: who holds it and, while it is open, where its cash came from.
     * Cash in = customer payments and pay-ins; cash out = refunds, pay-outs (expenses,
     * supplier payments), safe drops and bank deposits.
     *
     * @return array{holder: string|null, delegation: Delegation|null, cash: string|null, summary: array{opening: string, sales: string, cash_in: string, cash_out: string, expected: string}|null}
     */
    public function drawer(): array
    {
        $session = $this->authority->openSession();
        $summary = null;

        if ($session !== null) {
            $figures = $this->drawer->summary($session);
            $cashIn = Money::of($figures['customer_cash']);
            $cashOut = Money::of($figures['cash_refunds']);

            foreach ($figures['movements'] as $movement) {
                if ($movement['sign'] > 0) {
                    $cashIn = $cashIn->plus($movement['amount']);
                } else {
                    $cashOut = $cashOut->plus($movement['amount']);
                }
            }

            $summary = [
                'opening' => $figures['opening_float'],
                'sales' => $figures['cash_sales'],
                'cash_in' => (string) $cashIn,
                'cash_out' => (string) $cashOut,
                'expected' => $figures['expected_cash'],
            ];
        }

        return [
            'holder' => $this->authority->holder()?->name,
            'delegation' => $this->authority->activeDelegation(),
            'cash' => $summary['expected'] ?? null,
            'summary' => $summary,
        ];
    }

    /**
     * @return array{to_approve: int, to_receive: int, draft_receipts: int, open: list<array{id: int, number: string, supplier: string, status: string, color: string, total: string}>}
     */
    public function purchasing(): array
    {
        $open = PurchaseOrder::query()
            ->whereIn('status', [PurchaseOrderStatus::Submitted, PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent, PurchaseOrderStatus::Partial])
            ->with('supplier:id,name')
            ->orderBy('order_date')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'supplier' => (string) $order->supplier?->name,
                'status' => $order->status->label(),
                'color' => $order->status->color(),
                'total' => (string) Money::of((string) $order->total),
            ])
            ->all();

        return [
            'open' => $open,
            'to_approve' => DB::table('purchase_orders')->where('status', PurchaseOrderStatus::Submitted->value)->count(),
            'to_receive' => DB::table('purchase_orders')->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::Partial->value])->count(),
            'draft_receipts' => DB::table('goods_receipts')->where('status', GoodsReceiptStatus::Draft->value)->count(),
        ];
    }

    /**
     * @return array{active_items: int, low: int, expiring: int, expiry_days: int, low_items: list<array{id: int, code: string, name: string, on_hand: string, reorder_level: string}>}
     */
    public function stock(): array
    {
        $days = (int) $this->settings->get('inventory.expiry_alert_days', 30);

        return [
            'active_items' => Product::query()->active()->count(),
            'low' => $this->alerts->lowStockCount(),
            'expiring' => $this->alerts->expiringCount($days),
            'expiry_days' => $days,
            'low_items' => $this->alerts->whereLowStock(Product::query())
                ->addSelect(['products.id', 'products.short_code', 'products.name', 'products.reorder_level', 'on_hand' => StockAlerts::onHandSubquery()])
                ->orderByRaw('on_hand / products.reorder_level')
                ->limit(5)
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
     * Unpaid posted goods receipts, the one due first at the top. A receipt is due its
     * supplier's payment terms after it was received (as in the supplier ageing report).
     *
     * @return array{bills: list<array{id: int, supplier: string, invoice: string, due: string, total: string, paid: string, balance: string, status: string}>, outstanding: string, overdue: int}
     */
    public function supplierDues(): array
    {
        $due = 'DATE_ADD(DATE(goods_receipts.received_at), INTERVAL suppliers.payment_terms_days DAY)';
        $unpaid = fn () => DB::table('goods_receipts')
            ->join('suppliers', 'suppliers.id', '=', 'goods_receipts.supplier_id')
            ->where('goods_receipts.status', GoodsReceiptStatus::Posted->value)
            ->whereColumn('goods_receipts.amount_paid', '<', 'goods_receipts.total');

        $bills = $unpaid()
            ->select(['goods_receipts.id', 'goods_receipts.number', 'goods_receipts.supplier_invoice_no', 'goods_receipts.total', 'goods_receipts.amount_paid', 'suppliers.name AS supplier'])
            ->selectRaw("{$due} AS due_date")
            ->orderByRaw("{$due}, goods_receipts.id")
            ->limit(5)
            ->get()
            ->map(function (object $bill) {
                $dueDate = Carbon::parse((string) $bill->due_date);

                return [
                    'id' => (int) $bill->id,
                    'supplier' => (string) $bill->supplier,
                    'invoice' => (string) ($bill->supplier_invoice_no ?: $bill->number),
                    'due' => $dueDate->toDateString(),
                    'total' => (string) Money::of((string) $bill->total),
                    'paid' => (string) Money::of((string) $bill->amount_paid),
                    'balance' => (string) Money::of((string) $bill->total)->minus((string) $bill->amount_paid),
                    'status' => match (true) {
                        $dueDate->lt(today()) => 'overdue',
                        $dueDate->lte(today()->addDays(self::DUE_SOON_DAYS)) => 'due_soon',
                        default => 'not_due',
                    },
                ];
            })
            ->all();

        $totals = $unpaid()
            ->selectRaw("COALESCE(SUM(goods_receipts.total - goods_receipts.amount_paid), 0) AS outstanding, COUNT(CASE WHEN {$due} < CURDATE() THEN 1 END) AS overdue")
            ->first();

        return [
            'bills' => $bills,
            'outstanding' => (string) Money::of((string) $totals->outstanding),
            'overdue' => (int) $totals->overdue,
        ];
    }

    /**
     * Today's best-selling items by quantity sold (less returns), in each item's base unit.
     *
     * @return list<array{id: int, name: string, qty: string, unit: string}>
     */
    public function bestSellersToday(): array
    {
        $rows = DB::query()->fromSub($this->facts->lines(today(), today()->addDay()), 'f')
            ->groupBy('f.product_id')
            ->selectRaw('f.product_id, SUM(f.qty) AS qty')
            ->havingRaw('SUM(f.qty) > 0')
            ->orderByDesc('qty')
            ->limit(5)
            ->get();
        $products = Product::withTrashed()->with('baseUnit:id,symbol')->whereIn('id', $rows->pluck('product_id'))->get(['id', 'name', 'base_unit_id'])->keyBy('id');

        return $rows->map(fn (object $row) => [
            'id' => (int) $row->product_id,
            'name' => $products[$row->product_id]->name ?? '',
            'qty' => rtrim(rtrim((string) $row->qty, '0'), '.'),
            'unit' => $products[$row->product_id]->baseUnit->symbol ?? '',
        ])->all();
    }

    /**
     * The last settled sales.
     *
     * @return list<array{id: int, invoice: string, time: string, customer: string, counter: string, total: string, method: string, method_label: string}>
     */
    public function recentSales(): array
    {
        return Sale::query()
            ->whereIn('status', SalesFacts::SOLD)
            ->whereNotNull('settled_at')
            ->with(['customer:id,name', 'invoicedTerminal'])
            ->latest('settled_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'invoice' => (string) $sale->invoice_no,
                'time' => $sale->settled_at->isToday() ? $sale->settled_at->format('H:i') : $sale->settled_at->format('j M H:i'),
                'customer' => $sale->customer->name ?? 'Walk-in',
                'counter' => $sale->invoicedTerminal?->displayName() ?? '',
                'total' => (string) Money::of((string) $sale->total),
                'method' => $sale->payment_method_intent->value,
                'method_label' => $sale->payment_method_intent->label(),
            ])
            ->all();
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

    /**
     * @param  Collection<int, array<string, int|string>>  $rows
     */
    private function total(Collection $rows, string $key): BigDecimal
    {
        return $rows->reduce(fn (BigDecimal $sum, array $row) => $sum->plus((string) ($row[$key] ?? '0')), Money::zero());
    }
}
