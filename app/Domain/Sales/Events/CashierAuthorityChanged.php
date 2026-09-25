<?php

namespace App\Domain\Sales\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cashier authority moved (handover, take back, revoke, expiry). Top bars update.
 */
class CashierAuthorityChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array{id: int, name: string}|null  $holder
     */
    public function __construct(
        public ?array $holder,
        public string $message = '',
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('cashier-authority')];
    }

    public function broadcastAs(): string
    {
        return 'authority.changed';
    }
}
