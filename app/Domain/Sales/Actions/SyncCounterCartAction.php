<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Identity\Models\Terminal;
use App\Domain\Inventory\Support\Qty;
use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Events\CounterCartUpdated;
use App\Domain\Sales\Models\CounterEvent;
use App\Domain\Sales\Services\CartPricer;
use App\Domain\Sales\Services\CounterEventRecorder;
use App\Domain\Sales\Services\LiveCartStore;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * POST /api/pos/cart-sync (debounced 300 ms on the counter).
 *
 * Reprices the cart on the server, stores it as live_cart:{terminal_id}, turns the
 * difference from the previous snapshot into counter_events (added, qty changed,
 * removed, cleared, tendered) and broadcasts the new state to Live Billing.
 */
class SyncCounterCartAction
{
    public function __construct(
        private readonly CartPricer $pricer,
        private readonly LiveCartStore $store,
        private readonly CounterEventRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> the stored snapshot
     */
    public function handle(Terminal $terminal, User $user, array $payload): array
    {
        $priced = $this->pricer->price($payload, $user, strict: false);
        $previous = $this->store->get($terminal->id);

        if ($previous !== null && ($previous['cart_uuid'] ?? null) !== $priced['cart_uuid']) {
            // A new bill: the previous one was printed, held or replaced; nothing to diff.
            $previous = null;
        }

        $snapshot = [
            ...$priced,
            'terminal_id' => $terminal->id,
            'counter_no' => $terminal->counter_no,
            'user' => ['id' => $user->id, 'name' => $user->name],
            'status' => $this->status($priced),
            'updated_at' => now()->toIso8601String(),
        ];

        $events = $this->diff($terminal, $user, $previous, $snapshot);

        if ($priced['lines'] === [] && $events === []) {
            $this->store->forget($terminal->id);
        } else {
            $this->store->put($terminal->id, $snapshot);
        }

        $this->store->touch($terminal->id, $user->id, $user->name);

        LiveBroadcast::send(new CounterCartUpdated(
            $terminal->id,
            self::forDisplay($snapshot),
            array_map(fn (CounterEvent $event) => $event->toBroadcast(), $events),
        ));

        return $snapshot;
    }

    /**
     * The snapshot without the unit lists (only the counter needs those).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function forDisplay(array $snapshot): array
    {
        $snapshot['lines'] = array_map(function (array $line): array {
            unset($line['units']);

            return $line;
        }, $snapshot['lines'] ?? []);

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $priced
     */
    private function status(array $priced): string
    {
        if ($priced['lines'] === []) {
            return 'idle';
        }

        return $priced['tendered'] !== null ? 'payment' : 'billing';
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $current
     * @return list<CounterEvent>
     */
    private function diff(Terminal $terminal, User $user, ?array $previous, array $current): array
    {
        $uuid = $current['cart_uuid'] ?: null;
        $before = $this->linesByKey($previous['lines'] ?? []);
        $after = $this->linesByKey($current['lines']);
        $events = [];
        $record = function (CounterEventType $type, array $payload) use ($terminal, $user, $uuid, &$events): void {
            $events[] = $this->recorder->record($terminal->id, $user->id, $type, $uuid, $payload);
        };

        if ($before->isNotEmpty() && $after->isEmpty()) {
            $record(CounterEventType::CartCleared, [
                'lines' => $before->count(),
                'total' => $previous['total'] ?? '0.00',
                'items' => $before->map(fn (array $line) => $this->linePayload($line))->values()->all(),
            ]);

            return $events;
        }

        foreach ($after as $key => $line) {
            $old = $before->get($key);

            if ($old === null) {
                $record(CounterEventType::ItemAdded, $this->linePayload($line));
            } elseif (Qty::of($old['qty'])->compareTo($line['qty']) !== 0 || $old['unit_id'] !== $line['unit_id']) {
                $record(CounterEventType::QtyChanged, [
                    ...$this->linePayload($line),
                    'from' => Qty::format($old['qty']).' '.$old['unit'],
                    'to' => Qty::format($line['qty']).' '.$line['unit'],
                ]);
            }
        }

        foreach ($before as $key => $line) {
            if (! $after->has($key)) {
                $record(CounterEventType::ItemRemoved, $this->linePayload($line));
            }
        }

        $tendered = $current['tendered'];
        $oldTendered = $previous['tendered'] ?? null;

        if ($tendered !== null && ($oldTendered === null || Money::of($oldTendered)->compareTo($tendered) !== 0)) {
            $record(CounterEventType::Tendered, ['amount' => $tendered, 'total' => $current['total'], 'method' => $current['payment_method']]);
        }

        return $events;
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function linesByKey(mixed $lines): Collection
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [];

        return collect($rows)->keyBy(fn (array $line): string => (string) $line['key']);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function linePayload(array $line): array
    {
        return [
            'product_id' => $line['product_id'],
            'variant_id' => $line['variant_id'],
            'name' => $line['name'],
            'qty' => Qty::format($line['qty']),
            'unit' => $line['unit'],
            'line_total' => $line['line_total'],
        ];
    }
}
