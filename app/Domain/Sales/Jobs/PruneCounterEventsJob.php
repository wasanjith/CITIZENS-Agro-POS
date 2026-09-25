<?php

namespace App\Domain\Sales\Jobs;

use App\Domain\Sales\Models\CounterEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nightly: counter_events are kept for 90 days.
 */
class PruneCounterEventsJob implements ShouldQueue
{
    use Queueable;

    public const KEEP_DAYS = 90;

    public function handle(): void
    {
        CounterEvent::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }
}
