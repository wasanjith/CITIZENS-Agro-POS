<?php

namespace App\Domain\HR\Services;

use App\Domain\HR\Enums\AttendanceStatus;
use App\Domain\HR\Enums\SalaryAdvanceStatus;
use App\Domain\HR\Enums\SalaryComponentType;
use App\Domain\HR\Models\Attendance;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\Holiday;
use App\Domain\HR\Models\LeaveRequest;
use App\Domain\HR\Models\SalaryAdvance;
use App\Domain\HR\Models\SalaryComponent;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\Settings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * One employee's pay for one month (IMPLEMENTATION_PLAN, Phase 6):
 *
 *   no-pay      = no-pay days × basic ÷ working days
 *   OT          = OT hours × basic × OT multiplier ÷ OT divisor (Settings → HR)
 *   gross       = basic − no-pay + allowances + OT
 *   EPF base    = basic − no-pay + allowances marked "EPF applies"
 *   EPF 8 % / 12 %, ETF 3 % of the EPF base (EPF members only; rates in Settings → HR)
 *   net         = gross − EPF (employee) − advance installments − other deductions
 *
 * Working days are the shift's days in the month less holidays. On a working day:
 * present = worked, half day = ½ worked (the other ½ is paid if it was approved paid
 * leave), paid leave = paid, unpaid leave / absent / no record = no-pay, and days before
 * joining or after leaving are no-pay. Days still to come in the month count as worked.
 */
class PayrollCalculator
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array{no_pay_days?: string|null, ot_hours?: string|null, advance?: string|null}  $overrides
     * @param  list<array{component_name: string, type: SalaryComponentType, amount: string, is_epf_applicable: bool}>  $manualLines
     * @return array{fields: array<string, mixed>, lines: list<array{component_name: string, type: SalaryComponentType, amount: string, is_epf_applicable: bool, is_manual: bool}>, advances: array<int, string>}
     */
    public function calculate(Employee $employee, string $month, array $overrides = [], array $manualLines = []): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $basic = Money::of($employee->basic_salary);

        $days = $this->days($employee, $start, $end);
        $workingDays = BigDecimal::of((string) $days['working'])->toScale(1);
        $noPayDays = isset($overrides['no_pay_days'])
            ? BigDecimal::of((string) $overrides['no_pay_days'])->toScale(1)
            : $days['no_pay'];

        if ($noPayDays->isGreaterThan($workingDays)) {
            $noPayDays = $workingDays;
        }

        $noPay = $workingDays->isPositive()
            ? $basic->multipliedBy($noPayDays)->dividedBy($workingDays, 2, RoundingMode::HalfUp)
            : Money::zero();

        // Allowances and deductions from the employee's salary components, then the owner's own lines.
        $lines = [];
        $components = $employee->salaryComponents()->where('is_active', true)->orderBy('salary_components.id')->get();

        foreach ($components as $component) {
            /** @var SalaryComponent $component */
            $amount = $component->amountFor($basic, $component->getRelation('pivot')->getAttribute('value_override'));

            if ($amount->isPositive()) {
                $lines[] = [
                    'component_name' => $component->name,
                    'type' => $component->type,
                    'amount' => (string) $amount,
                    'is_epf_applicable' => $component->type === SalaryComponentType::Allowance && $component->is_epf_applicable,
                    'is_manual' => false,
                ];
            }
        }

        foreach ($manualLines as $line) {
            if (Money::of($line['amount'])->isPositive()) {
                $lines[] = [...$line, 'amount' => (string) Money::of($line['amount']), 'is_manual' => true];
            }
        }

        $allowances = Money::zero();
        $epfAllowances = Money::zero();
        $otherDeductions = Money::zero();

        foreach ($lines as $line) {
            if ($line['type'] === SalaryComponentType::Allowance) {
                $allowances = $allowances->plus($line['amount']);
                $epfAllowances = $line['is_epf_applicable'] ? $epfAllowances->plus($line['amount']) : $epfAllowances;
            } else {
                $otherDeductions = $otherDeductions->plus($line['amount']);
            }
        }

        // Overtime.
        $otHours = isset($overrides['ot_hours'])
            ? BigDecimal::of((string) $overrides['ot_hours'])->toScale(2, RoundingMode::HalfUp)
            : BigDecimal::of((string) $days['ot_minutes'])->dividedBy(60, 2, RoundingMode::HalfUp);
        $divisor = max(1, (int) $this->settings->get('hr.ot_hours_divisor', 240));
        $otRate = $basic->multipliedBy((string) $this->settings->get('hr.ot_multiplier', 1.5))->dividedBy($divisor, 2, RoundingMode::HalfUp);
        $otAmount = Money::of($otHours->multipliedBy($otRate));

        $gross = $basic->minus($noPay)->plus($allowances)->plus($otAmount);

        // EPF / ETF.
        $epfBase = $basic->minus($noPay)->plus($epfAllowances);
        $rate = fn (string $key, float $default): BigDecimal => $employee->is_epf_member && $epfBase->isPositive()
            ? $epfBase->multipliedBy((string) $this->settings->get($key, $default))->dividedBy(100, 2, RoundingMode::HalfUp)
            : Money::zero();
        $epfEmployee = $rate('hr.epf_employee_rate', 8);
        $epfEmployer = $rate('hr.epf_employer_rate', 12);
        $etf = $rate('hr.etf_rate', 3);

        // Advance installments, never more than what is left to pay.
        $available = $gross->minus($epfEmployee)->minus($otherDeductions);
        $advances = $this->advanceInstallments($employee, $end, $overrides['advance'] ?? null, $available);
        $advanceTotal = array_reduce($advances, fn (BigDecimal $sum, string $amount) => $sum->plus($amount), Money::zero());

        $totalDeductions = $epfEmployee->plus($advanceTotal)->plus($otherDeductions);

        return [
            'fields' => [
                'employee_name' => $employee->full_name,
                'employee_name_si' => $employee->name_si,
                'designation' => $employee->designation,
                'epf_no' => $employee->epf_no,
                'is_epf_member' => $employee->is_epf_member,
                'basic' => (string) $basic,
                'working_days' => (string) $workingDays,
                'days_worked' => (string) $days['worked'],
                'paid_leave_days' => (string) $days['paid_leave'],
                'no_pay_days' => (string) $noPayDays,
                'no_pay_deduction' => (string) $noPay,
                'allowances' => (string) $allowances,
                'ot_hours' => (string) $otHours,
                'ot_rate' => (string) $otRate,
                'ot_amount' => (string) $otAmount,
                'gross' => (string) $gross,
                'epf_base' => (string) ($employee->is_epf_member ? $epfBase : Money::zero()),
                'epf_employee' => (string) $epfEmployee,
                'epf_employer' => (string) $epfEmployer,
                'etf' => (string) $etf,
                'advance_deduction' => (string) $advanceTotal,
                'other_deductions' => (string) $otherDeductions,
                'total_deductions' => (string) $totalDeductions,
                'net' => (string) $gross->minus($totalDeductions),
            ],
            'lines' => $lines,
            'advances' => $advances,
        ];
    }

    /**
     * Day counts for the month from the attendance.
     *
     * @return array{working: int, worked: BigDecimal, paid_leave: BigDecimal, no_pay: BigDecimal, ot_minutes: int}
     */
    public function days(Employee $employee, Carbon $start, Carbon $end): array
    {
        $shift = $employee->workingShift();
        $holidays = Holiday::between($start, $end);
        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (Attendance $row) => $row->date->toDateString());
        $paidLeave = LeaveRequest::query()
            ->whereIn('id', $attendance->pluck('leave_request_id')->filter()->unique())
            ->with('leaveType')
            ->get()
            ->mapWithKeys(fn (LeaveRequest $request) => [$request->id => $request->leaveType->is_paid]);

        $working = 0;
        $worked = BigDecimal::of('0.0');
        $leave = BigDecimal::of('0.0');
        $noPay = BigDecimal::of('0.0');
        $half = BigDecimal::of('0.5');

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if (($shift !== null && ! $shift->worksOn($day)) || isset($holidays[$day->toDateString()])) {
                continue;
            }

            $working++;
            $row = $attendance->get($day->toDateString());

            if (! $employee->isEmployedOn($day)) {
                $noPay = $noPay->plus(1);

                continue;
            }

            if ($row === null) {
                $day->gt(today()) ? $worked = $worked->plus(1) : $noPay = $noPay->plus(1);

                continue;
            }

            $isPaidLeave = $row->leave_request_id !== null && ($paidLeave[$row->leave_request_id] ?? false);

            switch ($row->status) {
                case AttendanceStatus::Present:
                    $worked = $worked->plus(1);
                    break;
                case AttendanceStatus::HalfDay:
                    $worked = $worked->plus($half);
                    $isPaidLeave ? $leave = $leave->plus($half) : $noPay = $noPay->plus($half);
                    break;
                case AttendanceStatus::Leave:
                    $isPaidLeave ? $leave = $leave->plus(1) : $noPay = $noPay->plus(1);
                    break;
                case AttendanceStatus::Absent:
                    $noPay = $noPay->plus(1);
                    break;
                case AttendanceStatus::Holiday:
                    // A working day marked as a holiday (shop closed): paid, not worked.
                    break;
            }
        }

        return [
            'working' => $working,
            'worked' => $worked->toScale(1),
            'paid_leave' => $leave->toScale(1),
            'no_pay' => $noPay->toScale(1),
            'ot_minutes' => (int) $attendance->sum('ot_minutes'),
        ];
    }

    /**
     * Installments to recover this month, oldest advance first. An owner's amount
     * replaces the planned installments. Capped at what the employee has left.
     *
     * @return array<int, string> advance id => amount
     */
    private function advanceInstallments(Employee $employee, Carbon $monthEnd, BigDecimal|string|null $override, BigDecimal $available): array
    {
        $advances = SalaryAdvance::query()
            ->where('employee_id', $employee->id)
            ->where('status', SalaryAdvanceStatus::Active)
            ->whereDate('date', '<=', $monthEnd)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $budget = $override !== null ? Money::of($override) : null;
        $limit = $available->isPositive() ? $available : Money::zero();
        $plan = [];

        foreach ($advances as $advance) {
            $amount = $budget !== null ? $advance->outstanding() : $advance->nextInstallment();

            if ($budget !== null && $amount->isGreaterThan($budget)) {
                $amount = $budget;
            }

            if ($amount->isGreaterThan($limit)) {
                $amount = $limit;
            }

            if (! $amount->isPositive()) {
                continue;
            }

            $plan[$advance->id] = (string) $amount;
            $limit = $limit->minus($amount);
            $budget = $budget?->minus($amount);
        }

        return $plan;
    }
}
