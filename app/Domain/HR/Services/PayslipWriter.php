<?php

namespace App\Domain\HR\Services;

use App\Domain\HR\Enums\SalaryComponentType;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\PayrollRun;
use App\Domain\HR\Models\Payslip;
use App\Domain\HR\Models\PayslipLine;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;

/**
 * Writes a payslip from the calculator, keeping the owner's edits (overrides, own lines,
 * note), and keeps the run's totals in step.
 */
class PayslipWriter
{
    public function __construct(private readonly PayrollCalculator $calculator) {}

    public function write(PayrollRun $run, Employee $employee, ?Payslip $payslip = null): Payslip
    {
        $payslip ??= new Payslip(['payroll_run_id' => $run->id, 'employee_id' => $employee->id]);

        $manual = $payslip->exists
            ? $payslip->lines()->where('is_manual', true)->orderBy('id')->get()->map(fn (PayslipLine $line) => [
                'component_name' => $line->component_name,
                'type' => $line->type,
                'amount' => (string) $line->amount,
                'is_epf_applicable' => $line->is_epf_applicable,
            ])->all()
            : [];

        $result = $this->calculator->calculate($employee, $run->month, [
            'no_pay_days' => $payslip->no_pay_days_override,
            'ot_hours' => $payslip->ot_hours_override,
            'advance' => $payslip->advance_override,
        ], $manual);

        $payslip->fill($result['fields'])->save();
        $payslip->lines()->delete();
        $payslip->lines()->createMany($result['lines']);

        return $payslip;
    }

    /**
     * Owner's edits during review. Blank override = back to the calculated value.
     *
     * @param  array{no_pay_days?: string|null, ot_hours?: string|null, advance?: string|null, note?: string|null, lines?: list<array{name?: string|null, type?: string|null, amount?: string|null, epf?: bool|string|null}>}  $data
     */
    public function edit(Payslip $payslip, array $data): Payslip
    {
        $payslip->forceFill([
            'no_pay_days_override' => filled($data['no_pay_days'] ?? null) ? (string) $data['no_pay_days'] : null,
            'ot_hours_override' => filled($data['ot_hours'] ?? null) ? (string) $data['ot_hours'] : null,
            'advance_override' => filled($data['advance'] ?? null) ? (string) $data['advance'] : null,
            'note' => filled($data['note'] ?? null) ? mb_substr((string) $data['note'], 0, 255) : null,
        ])->save();

        $payslip->lines()->where('is_manual', true)->delete();

        foreach ($data['lines'] ?? [] as $line) {
            $type = SalaryComponentType::tryFrom((string) ($line['type'] ?? ''));

            if ($type === null || blank($line['name'] ?? null) || ! Money::of($line['amount'] ?? '0')->isPositive()) {
                continue;
            }

            $payslip->lines()->create([
                'component_name' => mb_substr((string) $line['name'], 0, 80),
                'type' => $type,
                'amount' => (string) Money::of($line['amount']),
                'is_epf_applicable' => $type === SalaryComponentType::Allowance && filter_var($line['epf'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'is_manual' => true,
            ]);
        }

        $run = $payslip->payrollRun()->firstOrFail();
        $payslip = $this->write($run, $payslip->employee()->firstOrFail(), $payslip);
        $this->refreshTotals($run);

        return $payslip;
    }

    public function refreshTotals(PayrollRun $run): void
    {
        $sum = fn (string $column): string => (string) $run->payslips()->get([$column])->reduce(fn (BigDecimal $total, Payslip $payslip) => $total->plus($payslip->{$column}), Money::zero());

        $run->forceFill([
            'total_gross' => $sum('gross'),
            'total_deductions' => $sum('total_deductions'),
            'total_net' => $sum('net'),
            'total_epf_employee' => $sum('epf_employee'),
            'total_epf_employer' => $sum('epf_employer'),
            'total_etf' => $sum('etf'),
        ])->save();
    }
}
