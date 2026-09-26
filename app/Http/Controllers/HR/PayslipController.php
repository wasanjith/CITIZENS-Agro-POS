<?php

namespace App\Http\Controllers\HR;

use App\Domain\Finance\Services\FinancePosting;
use App\Domain\HR\Enums\SalaryComponentType;
use App\Domain\HR\Models\Payslip;
use App\Domain\HR\Services\PayslipWriter;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * One payslip: the breakdown, the owner's edits while the payroll is not approved, and
 * the Sinhala / English PDF.
 */
class PayslipController extends Controller
{
    public function show(Payslip $payslip, FinancePosting $posting): View
    {
        $this->authorize('view', $payslip);

        return view('hr.payslips.show', [
            'payslip' => $payslip->load(['payrollRun', 'employee', 'lines', 'recoveries.advance', 'bankAccount', 'paidBy']),
            'entries' => $posting->entriesFor($payslip),
            'types' => SalaryComponentType::options(),
        ]);
    }

    public function update(Request $request, Payslip $payslip, PayslipWriter $writer): RedirectResponse
    {
        $this->authorize('update', $payslip);

        $validated = $request->validate([
            'no_pay_days' => ['nullable', 'numeric', 'min:0', 'max:31', 'multiple_of:0.5'],
            'ot_hours' => ['nullable', 'numeric', 'min:0', 'max:300', 'decimal:0,2'],
            'advance' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['array', 'max:20'],
            'lines.*.name' => ['nullable', 'string', 'max:80'],
            'lines.*.type' => ['nullable', Rule::enum(SalaryComponentType::class)],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'lines.*.epf' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($payslip, $validated, $writer): void {
            $run = $payslip->payrollRun()->lockForUpdate()->firstOrFail();

            if (! $run->isEditable()) {
                throw ValidationException::withMessages(['payslip' => 'This payroll is approved. Reopen it to make changes.']);
            }

            $writer->edit($payslip, $validated);
        });

        return redirect()->route('hr.payslips.show', $payslip)->with('success', 'Payslip recalculated with your changes.');
    }

    public function pdf(Request $request, Payslip $payslip, Settings $settings): PdfBuilder
    {
        $this->authorize('view', $payslip);

        $run = $payslip->payrollRun()->firstOrFail();

        return Pdf::view('print.payslips-a4', [
            'run' => $run,
            'payslips' => collect([$payslip->load('lines')]),
            'language' => PayrollController::language($request),
            'shop' => $settings->group('shop'),
        ])->format('a4')->name("payslip-{$run->month}-".str($payslip->employee_name)->slug().'.pdf');
    }
}
