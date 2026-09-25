<?php

namespace App\Domain\Sales\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A counter changed its cart (Live Billing column update).
 */
class CounterCartUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $cart  live cart snapshot (no costs)
     * @param  list<array<string, mixed>>  $events  new ticker lines
     */
    public function __construct(
        public int $terminalId,
        public array $cart,
        public array $events = [],
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('live-billing')];
    }

    public function broadcastAs(): string
    {
        return 'cart.updated';
    }
}
