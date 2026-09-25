<?php

namespace App\Domain\Sales\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A counter asks the cashier to approve a discount.
 */
class ApprovalRequested implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $request  ApprovalRequest::toBroadcast()
     */
    public function __construct(public array $request) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('live-billing')];
    }

    public function broadcastAs(): string
    {
        return 'approval.requested';
    }
}
