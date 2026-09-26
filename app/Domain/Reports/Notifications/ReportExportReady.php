<?php

namespace App\Domain\Reports\Notifications;

use App\Domain\System\Notifications\AppNotification;

/**
 * A queued Excel export has finished.
 */
class ReportExportReady extends AppNotification
{
    public function __construct(
        public readonly string $reportTitle,
        public readonly string $subtitle,
        public readonly string $file,
    ) {}

    public function title(): string
    {
        return "{$this->reportTitle} is ready";
    }

    public function message(): string
    {
        return "Excel file for {$this->subtitle}. Kept for 7 days.";
    }

    public function url(): string
    {
        return route('reports.downloads.show', $this->file);
    }
}
