<?php

namespace App\Domain\Sales\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a real-time event without letting a stopped Reverb server break the sale.
 * Screens fall back to polling (Live Billing snapshot, counter inbox) when it is down.
 */
final class LiveBroadcast
{
    public static function send(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $exception) {
            Log::warning('Real-time broadcast failed; screens will catch up by polling.', [
                'event' => $event::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
