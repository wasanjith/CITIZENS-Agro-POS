<?php

namespace App\Domain\Sales\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The cashier settled an invoice (money in the drawer, stock issued).
 */
class InvoiceSettled implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $sale  Sale::liveSummary()
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(
        public array $sale,
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
        return 'invoice.settled';
    }
}
