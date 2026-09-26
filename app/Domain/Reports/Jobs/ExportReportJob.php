<?php

namespace App\Domain\Reports\Jobs;

use App\Domain\Reports\Exports\ReportExport;
use App\Domain\Reports\Notifications\ReportExportReady;
use App\Domain\Reports\ReportRegistry;
use App\Domain\Reports\Support\ReportInput;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Builds a large report's Excel file in the background and tells the user (bell) when it is ready.
 * Files are kept under storage/app/private/exports/{user id}/ and removed after 7 days.
 */
class ExportReportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    /**
     * @param  array<string, string>  $query
     */
    public function __construct(
        public readonly string $reportKey,
        public readonly array $query,
        public readonly int $userId,
    ) {}

    public function handle(ReportRegistry $registry): void
    {
        $user = User::query()->find($this->userId);
        $report = $registry->find($this->reportKey);

        if ($user === null || $report === null || ! $report->canBeViewedBy($user)) {
            return;
        }

        $input = ReportInput::fromQuery($report, $this->query, $user);
        $file = Str::slug($report->title()).'-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.xlsx';

        Excel::store(new ReportExport($report->title(), $report->columns($input), $report->run($input)->rows), self::directory($user->id).'/'.$file, 'local');

        $user->notify(new ReportExportReady($report->title(), $report->subtitle($input), $file));
    }

    public static function directory(int $userId): string
    {
        return 'exports/'.$userId;
    }
}
