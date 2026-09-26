<?php

namespace App\Domain\HR\Actions;

use App\Domain\HR\Enums\PayrollStatus;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\PayrollRun;
use App\Domain\HR\Services\PayslipWriter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Calculate (or recalculate) the payroll of a month: one payslip per employee who was
 * employed in the month. Recalculating keeps the owner's edits on each payslip. Only a
 * run that is not approved yet can be recalculated.
 */
class CalculatePayrollAction
{
    public function __construct(private readonly PayslipWriter $writer) {}

    public function handle(string $month, User $user): PayrollRun
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw ValidationException::withMessages(['month' => 'Choose the month.']);
        }

        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

        if ($start->isAfter(today()->startOfMonth())) {
            throw ValidationException::withMessages(['month' => 'Payroll cannot be run for a future month.']);
        }

        return DB::transaction(function () use ($month, $start, $user): PayrollRun {
            $run = PayrollRun::query()->where('month', $month)->lockForUpdate()->first();

            if ($run !== null && ! $run->isEditable()) {
                throw ValidationException::withMessages(['month' => "The payroll for {$run->label()} is already {$run->status->label()}."]);
            }

            $run ??= PayrollRun::create(['month' => $month, 'status' => PayrollStatus::Calculated, 'created_by' => $user->id]);
            $end = $start->copy()->endOfMonth()->startOfDay();

            $employees = Employee::query()
                ->employedBetween($start, $end)
                ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereDate('leave_date', '>=', $start))
                ->with('shift')
                ->orderBy('code')
                ->get();

            if ($employees->isEmpty()) {
                throw ValidationException::withMessages(['month' => 'Nobody was employed in '.$start->format('F Y').'. Add the employees first.']);
            }

            $existing = $run->payslips()->get()->keyBy('employee_id');

            foreach ($employees as $employee) {
                $this->writer->write($run, $employee, $existing->get($employee->id));
            }

            $run->payslips()->whereNotIn('employee_id', $employees->pluck('id'))->delete();
            $this->writer->refreshTotals($run);

            return $run->refresh();
        });
    }
}
