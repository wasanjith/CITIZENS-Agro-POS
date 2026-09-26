<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Exports\ReportExport;
use App\Domain\Reports\Jobs\ExportReportJob;
use App\Domain\Reports\Report;
use App\Domain\Reports\ReportRegistry;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Reports page and every report on it: filter bar → table → Excel / PDF.
 */
class ReportController extends Controller
{
    /** Rows shown on screen; the Excel file has them all. */
    public const SCREEN_ROWS = 2000;

    /** Excel exports with more rows are built in the background. */
    public const QUEUE_AFTER_ROWS = 5000;

    /** PDFs are refused above this (use Excel). */
    public const PDF_ROWS = 3000;

    public function __construct(private readonly ReportRegistry $registry) {}

    public function index(Request $request): View
    {
        $menu = $this->registry->menuFor($request->user());
        abort_if($menu->isEmpty(), 403);

        return view('reports.index', ['menu' => $menu]);
    }

    public function show(Request $request, string $report): View
    {
        $report = $this->report($request, $report);
        $input = ReportInput::fromQuery($report, $request->query(), $request->user());
        $result = $report->run($input);

        return view('reports.show', [
            'report' => $report,
            'input' => $input,
            'filters' => $report->filters($request->user()),
            'columns' => $report->columns($input),
            'result' => $result,
            'rows' => array_slice($result->rows, 0, self::SCREEN_ROWS),
            'hiddenRows' => max(0, count($result->rows) - self::SCREEN_ROWS),
        ]);
    }

    public function export(Request $request, string $report, string $format, Settings $settings): BinaryFileResponse|PdfBuilder|RedirectResponse
    {
        $report = $this->report($request, $report);
        $input = ReportInput::fromQuery($report, $request->query(), $request->user());
        $result = $report->run($input);
        $name = Str::slug($report->title()).'-'.$input->from->format('Ymd').($report->usesPeriod() && ! $input->from->eq($input->to) ? '-'.$input->to->format('Ymd') : '');

        if ($format === 'xlsx') {
            if (count($result->rows) > self::QUEUE_AFTER_ROWS) {
                ExportReportJob::dispatch($report->key(), $input->toQuery(), $request->user()->id);

                return back()->with('success', 'This report is large, so the Excel file is being made in the background. The bell will tell you when it is ready.');
            }

            return Excel::download(new ReportExport($report->title(), $report->columns($input), $result->rows), $name.'.xlsx');
        }

        if (count($result->rows) > self::PDF_ROWS) {
            return back()->with('error', 'This report has too many lines for a PDF ('.number_format(count($result->rows)).'). Download it as Excel, or choose a shorter period.');
        }

        $pdf = Pdf::view('print.report-a4', [
            'report' => $report,
            'input' => $input,
            'columns' => $report->columns($input),
            'result' => $result,
            'shop' => $settings->group('shop'),
        ])->format('a4')->name($name.'.pdf');

        return $report->landscape($input) ? $pdf->landscape() : $pdf;
    }

    /**
     * A finished background export (link from the bell).
     */
    public function download(Request $request, string $file): StreamedResponse
    {
        abort_unless(preg_match('/^[a-z0-9-]+\.xlsx$/', $file) === 1, 404);

        $path = ExportReportJob::directory($request->user()->id).'/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404, 'This file has been removed (exports are kept for 7 days). Export the report again.');

        return Storage::disk('local')->download($path, $file);
    }

    private function report(Request $request, string $key): Report
    {
        $report = $this->registry->find($key);
        abort_if($report === null, 404);
        abort_unless($report->canBeViewedBy($request->user()), 403);

        return $report;
    }
}
