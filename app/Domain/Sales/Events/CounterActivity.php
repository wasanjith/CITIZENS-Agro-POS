<?php

namespace App\Domain\Sales\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened at a counter that belongs in its Live Billing ticker (reprint, hold).
 */
class CounterActivity implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<array<string, mixed>>  $events  new ticker lines
     */
    public function __construct(
        public int $terminalId,
        public array $events,
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
        return 'counter.activity';
    }
}
