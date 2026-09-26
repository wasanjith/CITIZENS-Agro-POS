<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Enums\PayrollStatus;
use App\Domain\HR\Models\Attendance;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\PayrollRun;
use App\Domain\HR\Models\Payslip;
use App\Domain\HR\Services\PayrollCalculator;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * HR reports: attendance summary, payroll summary for a year, EPF / ETF for a month.
 */
class HrReportController extends Controller
{
    public function attendance(Request $request, PayrollCalculator $calculator): View
    {
        $this->authorizeReports($request);

        $start = $this->month($request);
        $end = $start->copy()->endOfMonth()->startOfDay();
        $upTo = $end->copy()->min(today());

        $employees = Employee::query()->employedBetween($start, $end)->where(fn ($query) => $query->where('is_active', true)->orWhereDate('leave_date', '>=', $start))->with('shift')->orderBy('full_name')->get();
        $stats = Attendance::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(late_minutes > 0) AS late_days, SUM(late_minutes) AS late_minutes, SUM(early_leave_minutes) AS early_minutes, SUM(ot_minutes) AS ot_minutes, SUM(clock_in IS NOT NULL AND clock_out IS NULL AND date < ?) AS missing_out', [today()->toDateString()])
            ->get()
            ->keyBy('employee_id');

        return view('hr.reports.attendance', [
            'start' => $start,
            'rows' => $employees->map(fn (Employee $employee) => [
                'employee' => $employee,
                'days' => $calculator->days($employee, $start, $upTo),
                'stats' => $stats->get($employee->id),
            ]),
        ]);
    }

    public function payroll(Request $request): View
    {
        $this->authorizeReports($request);

        $year = (int) $request->query('year', (string) today()->year);
        $year = $year >= 2020 && $year <= 2100 ? $year : today()->year;

        $runs = PayrollRun::query()->where('month', 'like', $year.'-%')->where('status', '!=', PayrollStatus::Calculated)->orderBy('month')->get();
        $byEmployee = Payslip::query()
            ->whereIn('payroll_run_id', $runs->pluck('id'))
            ->groupBy('employee_id', 'employee_name')
            ->selectRaw('employee_id, employee_name, COUNT(*) AS months, SUM(gross) AS gross, SUM(ot_amount) AS ot, SUM(no_pay_deduction) AS no_pay, SUM(epf_employee) AS epf_employee, SUM(epf_employer) AS epf_employer, SUM(etf) AS etf, SUM(advance_deduction) AS advances, SUM(net) AS net')
            ->orderBy('employee_name')
            ->get();

        return view('hr.reports.payroll', ['year' => $year, 'runs' => $runs, 'byEmployee' => $byEmployee]);
    }

    public function epfEtf(Request $request): View
    {
        $this->authorizeReports($request);

        $start = $this->month($request, today()->subMonthNoOverflow()->startOfMonth());
        $run = PayrollRun::query()->where('month', $start->format('Y-m'))->first();

        return view('hr.reports.epf-etf', [
            'start' => $start,
            'run' => $run,
            'payslips' => $run?->payslips()->where('is_epf_member', true)->orderBy('epf_no')->orderBy('employee_name')->get() ?? collect(),
        ]);
    }

    private function authorizeReports(Request $request): void
    {
        abort_unless($request->user()->can('reports.hr'), 403);
    }

    private function month(Request $request, ?Carbon $default = null): Carbon
    {
        $month = (string) $request->query('month', '');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
            ? Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay()
            : ($default ?? today()->startOfMonth());
    }
}
