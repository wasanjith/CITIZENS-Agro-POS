<?php

namespace App\Domain\Sales\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invoice was voided. The counter that printed it is offered its cart back.
 */
class InvoiceVoided implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $sale  Sale::liveSummary()
     * @param  array<string, mixed>|null  $restore  cart the counter can restore (INVOICED voids only)
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(
        public int $terminalId,
        public array $sale,
        public ?array $restore = null,
        public array $events = [],
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('live-billing'), new PrivateChannel("terminal.{$this->terminalId}")];
    }

    public function broadcastAs(): string
    {
        return 'invoice.voided';
    }
}
