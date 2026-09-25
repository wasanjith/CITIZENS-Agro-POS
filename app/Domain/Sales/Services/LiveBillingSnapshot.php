<?php

namespace App\Domain\Sales\Services;

use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Identity\Enums\TerminalType;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Sales\Actions\SyncCounterCartAction;
use App\Domain\Sales\Enums\ApprovalStatus;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\ApprovalRequest;
use App\Domain\Sales\Models\CounterEvent;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * GET /api/live-billing/snapshot: everything the Live Billing screen shows, built from
 * the Redis carts and the database. Used for the first paint and, every 3 s, while the
 * WebSocket connection is down. Contains no cost fields.
 */
class LiveBillingSnapshot
{
    /**
     * A counter that has not synced for this long is shown as offline (when presence
     * over WebSockets is not available).
     */
    private const ONLINE_SECONDS = 90;

    public function __construct(
        private readonly LiveCartStore $carts,
        private readonly DrawerCalculator $calculator,
        private readonly CashierAuthority $authority,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $terminals = Terminal::query()->active()->orderByRaw('counter_no IS NULL, counter_no')->get();
        $startOfDay = today();

        $waiting = Sale::query()
            ->with(['invoicedBy', 'invoicedTerminal', 'items'])
            ->where('status', SaleStatus::Invoiced)
            ->orderBy('invoiced_at')
            ->get()
            ->groupBy('invoiced_terminal_id');

        $today = Sale::query()
            ->whereIn('status', [SaleStatus::Settled, SaleStatus::PartiallyReturned, SaleStatus::Returned])
            ->where('settled_at', '>=', $startOfDay)
            ->groupBy('invoiced_terminal_id')
            ->selectRaw('invoiced_terminal_id, COUNT(*) AS count, SUM(total) AS total')
            ->toBase()
            ->get()
            ->keyBy('invoiced_terminal_id');

        $voidsToday = Sale::query()->where('status', SaleStatus::Void)->where('voided_at', '>=', $startOfDay)->count();

        $events = CounterEvent::query()
            ->where('created_at', '>=', $startOfDay)
            ->whereIn('terminal_id', $terminals->pluck('id'))
            ->latest('id')
            ->limit(200)
            ->get()
            ->groupBy('terminal_id');

        $columns = $terminals->map(fn (Terminal $terminal) => $this->column($terminal, $waiting->get($terminal->id, collect()), $today[$terminal->id] ?? null, $events->get($terminal->id, collect())));

        $session = $this->authority->openSession();

        return [
            'generated_at' => now()->toIso8601String(),
            'counters' => $columns->where('type', TerminalType::Counter->value)->values()->all(),
            'main' => $columns->firstWhere('type', TerminalType::MainCashier->value),
            'approvals' => ApprovalRequest::query()
                ->with(['terminal', 'requester'])
                ->where('status', ApprovalStatus::Pending)
                ->where('created_at', '>=', now()->subHours(12))
                ->oldest()
                ->get()
                ->map(fn (ApprovalRequest $request) => $request->toBroadcast())
                ->all(),
            'totals' => [
                'sales' => (string) $columns->reduce(fn ($sum, array $column) => $sum->plus($column['today']['total']), Money::zero()),
                'count' => $columns->sum('today.count'),
                'waiting' => $waiting->flatten()->count(),
                'voids' => $voidsToday,
                'drawer' => $session !== null ? (string) $this->calculator->expectedCash($session) : null,
                'drawer_holder' => $session?->holder->name,
            ],
            'cashier' => $this->authority->holderSummary(),
            'settle_warning_minutes' => (int) $this->settings->get('pos.settle_warning_minutes', 5),
        ];
    }

    /**
     * @param  Collection<int, Sale>  $waiting
     * @param  Collection<int, CounterEvent>  $events
     * @return array<string, mixed>
     */
    private function column(Terminal $terminal, Collection $waiting, ?object $today, Collection $events): array
    {
        $cart = $this->carts->get($terminal->id);
        $beat = $this->carts->heartbeat($terminal->id);
        $online = $beat !== null && Carbon::parse($beat['at'])->greaterThan(now()->subSeconds(self::ONLINE_SECONDS));
        $lines = $cart['lines'] ?? [];

        $status = match (true) {
            ! $online && $lines === [] && $waiting->isEmpty() => 'offline',
            $lines !== [] && ($cart['tendered'] ?? null) !== null => 'payment',
            $lines !== [] => 'billing',
            $waiting->isNotEmpty() => 'printed',
            default => 'idle',
        };

        return [
            'terminal_id' => $terminal->id,
            'type' => $terminal->type->value,
            'counter_no' => $terminal->counter_no,
            'name' => $terminal->displayName(),
            'code' => $terminal->code,
            'online' => $online,
            'user' => $beat['user'] ?? ($cart['user']['name'] ?? null),
            'status' => $status,
            'cart' => $cart !== null ? SyncCounterCartAction::forDisplay($cart) : null,
            'invoices' => $waiting->map(fn (Sale $sale) => $sale->liveSummary())->values()->all(),
            'events' => $events->take(10)->map(fn (CounterEvent $event) => $event->toBroadcast())->values()->all(),
            'today' => [
                'count' => (int) ($today->count ?? 0),
                'total' => (string) Money::of((string) ($today->total ?? '0')),
            ],
        ];
    }
}
