<?php

namespace App\Domain\Sales\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Printers page asked a terminal to print its test page.
 */
class TestPrintRequested implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $terminalId,
        public string $url,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("terminal.{$this->terminalId}")];
    }

    public function broadcastAs(): string
    {
        return 'print.test';
    }
}
