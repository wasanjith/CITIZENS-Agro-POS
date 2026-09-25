<?php

namespace App\Domain\CashDrawer\Services;

use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\CashDrawer\Enums\DrawerCloseReason;
use App\Domain\CashDrawer\Models\CashMovement;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Customers\Models\CustomerPayment;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Drawer arithmetic: expected cash, totals per payment method and per counter, and
 * the Z report for a day (a chain of sessions linked by handovers).
 *
 * expected cash = opening float + cash settled − cash refunded (voids, returns) + cash customer payments
 *                 + pay ins − pay outs − safe drops − bank deposits
 */
class DrawerCalculator
{
    /**
     * Denominations on the count screen (Rs), largest first.
     *
     * @var list<string>
     */
    public const DENOMINATIONS = ['5000', '2000', '1000', '500', '100', '50', '20', '10', '5', '2', '1'];

    public function expectedCash(DrawerSession $session): BigDecimal
    {
        $summary = $this->summary($session);

        return Money::of($summary['expected_cash']);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(DrawerSession $session): array
    {
        $payments = Payment::query()
            ->where('drawer_session_id', $session->id)
            ->groupBy('method')
            ->selectRaw('method, SUM(amount) AS amount, COUNT(*) AS count')
            ->toBase()
            ->get()
            ->keyBy('method');

        $movements = CashMovement::query()
            ->where('drawer_session_id', $session->id)
            ->groupBy('type')
            ->selectRaw('type, SUM(amount) AS amount')
            ->toBase()
            ->pluck('amount', 'type');

        $cashIn = Payment::query()->where('drawer_session_id', $session->id)->where('method', PaymentMethod::Cash)->where('amount', '>', 0)->sum('amount');
        $cashOut = Payment::query()->where('drawer_session_id', $session->id)->where('method', PaymentMethod::Cash)->where('amount', '<', 0)->sum('amount');

        $customerPayments = CustomerPayment::query()
            ->where('drawer_session_id', $session->id)
            ->groupBy('method')
            ->selectRaw('method, SUM(amount) AS amount, COUNT(*) AS count')
            ->toBase()
            ->get()
            ->keyBy('method');
        $customerCash = Money::of((string) ($customerPayments[PaymentMethod::Cash->value]->amount ?? '0'));

        $cash = Money::of((string) ($payments[PaymentMethod::Cash->value]->amount ?? '0'));
        $expected = Money::of($session->opening_float)->plus($cash)->plus($customerCash);

        foreach (CashMovementType::cases() as $type) {
            $amount = Money::of((string) ($movements[$type->value] ?? '0'));
            $expected = $type->sign() > 0 ? $expected->plus($amount) : $expected->minus($amount);
        }

        return [
            'opening_float' => (string) Money::of($session->opening_float),
            'cash_sales' => (string) Money::of((string) $cashIn),
            'cash_refunds' => (string) Money::of((string) $cashOut)->abs(),
            'customer_cash' => (string) $customerCash,
            'customer_payments' => collect(PaymentMethod::customerPaymentMethods())
                ->mapWithKeys(fn (PaymentMethod $method) => [$method->value => [
                    'label' => $method->label(),
                    'amount' => (string) Money::of((string) ($customerPayments[$method->value]->amount ?? '0')),
                    'count' => (int) ($customerPayments[$method->value]->count ?? 0),
                ]])
                ->filter(fn (array $row) => $row['count'] > 0)
                ->all(),
            'by_method' => collect(PaymentMethod::cases())
                ->mapWithKeys(fn (PaymentMethod $method) => [$method->value => [
                    'label' => $method->label(),
                    'amount' => (string) Money::of((string) ($payments[$method->value]->amount ?? '0')),
                    'count' => (int) ($payments[$method->value]->count ?? 0),
                ]])
                ->filter(fn (array $row) => $row['count'] > 0)
                ->all(),
            'movements' => collect(CashMovementType::cases())
                ->mapWithKeys(fn (CashMovementType $type) => [$type->value => [
                    'label' => $type->label(),
                    'amount' => (string) Money::of((string) ($movements[$type->value] ?? '0')),
                    'sign' => $type->sign(),
                ]])
                ->all(),
            'expected_cash' => (string) $expected,
        ];
    }

    /**
     * Settled totals per counter for the given sessions.
     *
     * @param  list<int>  $sessionIds
     * @return Collection<int, array{terminal: string, counter_no: int|null, count: int, total: string}>
     */
    public function perCounter(array $sessionIds): Collection
    {
        $rows = Sale::query()
            ->whereIn('drawer_session_id', $sessionIds)
            ->whereIn('status', [SaleStatus::Settled, SaleStatus::PartiallyReturned, SaleStatus::Returned])
            ->groupBy('invoiced_terminal_id')
            ->selectRaw('invoiced_terminal_id, COUNT(*) AS count, SUM(total) AS total')
            ->toBase()
            ->get()
            ->keyBy('invoiced_terminal_id');

        return $this->terminals($rows->keys()->all())->map(fn (Terminal $terminal) => [
            'terminal' => $terminal->displayName(),
            'counter_no' => $terminal->counter_no,
            'count' => (int) $rows[$terminal->id]->count,
            'total' => (string) Money::of((string) $rows[$terminal->id]->total),
        ])->values();
    }

    /**
     * Voided invoices per counter between two moments.
     *
     * @return Collection<int, array{terminal: string, count: int, total: string, numbers: list<string>}>
     */
    public function voidsPerCounter(\DateTimeInterface $from, \DateTimeInterface $to): Collection
    {
        $sales = Sale::query()
            ->where('status', SaleStatus::Void)
            ->whereBetween('voided_at', [$from, $to])
            ->orderBy('invoice_no')
            ->get(['id', 'invoice_no', 'invoiced_terminal_id', 'total']);

        return $this->terminals($sales->pluck('invoiced_terminal_id')->unique()->all())->map(function (Terminal $terminal) use ($sales) {
            $mine = $sales->where('invoiced_terminal_id', $terminal->id);

            return [
                'terminal' => $terminal->displayName(),
                'count' => $mine->count(),
                'total' => (string) $mine->reduce(fn (BigDecimal $sum, Sale $sale) => $sum->plus($sale->total), Money::zero()),
                'numbers' => array_values(array_map(fn (Sale $sale): string => (string) $sale->invoice_no, $mine->all())),
            ];
        })->values();
    }

    /**
     * The sessions of one business day ending with $last: follow previous_session_id back
     * while the earlier session was closed for a handover.
     *
     * @return EloquentCollection<int, DrawerSession> oldest first
     */
    public function chain(DrawerSession $last): EloquentCollection
    {
        $chain = new EloquentCollection([$last]);
        $current = $last;

        while ($current->previous_session_id !== null) {
            $previous = DrawerSession::query()->find($current->previous_session_id);

            if ($previous === null || $previous->close_reason !== DrawerCloseReason::Handover) {
                break;
            }

            $chain->prepend($previous);
            $current = $previous;
        }

        return $chain->values();
    }

    /**
     * Z report of the day that ends with this session (wholeDay), or the report of this
     * one session only (handover slip, X report of an open drawer).
     *
     * @return array<string, mixed>
     */
    public function zReport(DrawerSession $last, bool $wholeDay = true): array
    {
        $chain = ($wholeDay ? $this->chain($last) : new EloquentCollection([$last]))->load(['holder', 'closer', 'next.holder']);
        $ids = $chain->pluck('id')->all();
        $from = $chain->first()->opened_at;
        $to = $last->closed_at ?? now();

        $sessions = $chain->map(fn (DrawerSession $session) => [
            'id' => $session->id,
            'holder' => $session->holder->name,
            'handed_to' => $session->next?->holder->name,
            'opened_at' => $session->opened_at,
            'closed_at' => $session->closed_at,
            'close_reason' => $session->close_reason?->label(),
            'summary' => $this->summary($session),
            'expected_cash' => $session->expected_cash,
            'counted_cash' => $session->counted_cash,
            'variance' => $session->variance,
        ])->all();

        $byMethod = [];

        foreach ($sessions as $session) {
            foreach ($session['summary']['by_method'] as $method => $row) {
                $byMethod[$method] ??= ['label' => $row['label'], 'amount' => Money::zero(), 'count' => 0];
                $byMethod[$method]['amount'] = $byMethod[$method]['amount']->plus($row['amount']);
                $byMethod[$method]['count'] += $row['count'];
            }
        }

        $customerPayments = [];

        foreach ($sessions as $session) {
            foreach ($session['summary']['customer_payments'] as $method => $row) {
                $customerPayments[$method] ??= ['label' => $row['label'], 'amount' => Money::zero(), 'count' => 0];
                $customerPayments[$method]['amount'] = $customerPayments[$method]['amount']->plus($row['amount']);
                $customerPayments[$method]['count'] += $row['count'];
            }
        }

        $returns = SaleReturn::query()
            ->whereIn('drawer_session_id', $ids)
            ->groupBy('refund_method')
            ->selectRaw('refund_method, COUNT(*) AS count, SUM(total) AS total')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->refund_method => ['count' => (int) $row->count, 'total' => (string) Money::of((string) $row->total)]])
            ->all();

        $perCounter = $this->perCounter($ids);
        $voids = $this->voidsPerCounter($from, $to);

        $expected = $last->expected_cash ?? (string) $this->expectedCash($last);

        return [
            'session_id' => $last->id,
            'is_open' => $last->isOpen(),
            'terminal' => $last->terminal->displayName(),
            'from' => $from,
            'to' => $to,
            'sessions' => $sessions,
            'per_counter' => $perCounter->all(),
            'sales_total' => (string) $perCounter->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['total']), Money::zero()),
            'sales_count' => $perCounter->sum('count'),
            'by_method' => array_map(fn (array $row) => [...$row, 'amount' => (string) $row['amount']], $byMethod),
            'customer_payments' => array_map(fn (array $row) => [...$row, 'amount' => (string) $row['amount']], $customerPayments),
            'returns' => $returns,
            'voids' => $voids->all(),
            'void_count' => $voids->sum('count'),
            'opening_float' => (string) Money::of($chain->first()->opening_float),
            'expected_cash' => $expected,
            'counted_cash' => $last->counted_cash,
            'variance' => $last->variance,
            'total_variance' => (string) $chain->reduce(fn (BigDecimal $sum, DrawerSession $session) => $sum->plus($session->variance ?? '0'), Money::zero()),
        ];
    }

    /**
     * Total of a denomination count: ['5000' => 3, '1000' => 12, 'coins' => '37.50'].
     * (PHP stores the numeric note keys as integers.)
     *
     * @param  array<int|string, mixed>  $denominations
     */
    public function countTotal(array $denominations): BigDecimal
    {
        $total = Money::zero();

        foreach (self::DENOMINATIONS as $note) {
            $total = $total->plus(BigDecimal::of($note)->multipliedBy((int) ($denominations[$note] ?? 0)));
        }

        return $total->plus(Money::of($denominations['coins'] ?? '0'));
    }

    /**
     * @param  list<int|string>  $ids
     * @return Collection<int, Terminal>
     */
    private function terminals(array $ids): Collection
    {
        return Terminal::query()
            ->whereIn('id', $ids)
            ->orderByRaw('counter_no IS NULL, counter_no')
            ->get();
    }
}
