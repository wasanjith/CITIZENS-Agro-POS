<?php

namespace App\Domain\Sales\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The cashier approved or rejected a request; the counter updates its cart.
 */
class ApprovalDecided implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $request  ApprovalRequest::toBroadcast()
     */
    public function __construct(
        public int $terminalId,
        public array $request,
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
        return 'approval.decided';
    }
}
