<?php

namespace App\Domain\Reports\Jobs;

use App\Domain\Reports\Services\DailySalesFigures;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Nightly: rebuild the daily sales summaries of the last 40 days (late returns and voids
 * change past days) and remove queued report exports older than 7 days.
 */
class RebuildDailySalesSummariesJob implements ShouldQueue
{
    use Queueable;

    public const DAYS = 40;

    public const KEEP_EXPORT_DAYS = 7;

    public function handle(DailySalesFigures $figures): void
    {
        $figures->rebuild(today()->subDays(self::DAYS), today()->subDay());

        $disk = Storage::disk('local');

        foreach ($disk->allFiles('exports') as $file) {
            if ($disk->lastModified($file) < now()->subDays(self::KEEP_EXPORT_DAYS)->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
}
