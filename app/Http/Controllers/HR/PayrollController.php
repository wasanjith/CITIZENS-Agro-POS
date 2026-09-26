<?php

namespace App\Http\Controllers\HR;

use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Services\DrawerCash;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\HR\Actions\ApprovePayrollAction;
use App\Domain\HR\Actions\CalculatePayrollAction;
use App\Domain\HR\Actions\PayEpfEtfAction;
use App\Domain\HR\Actions\PaySalariesAction;
use App\Domain\HR\Actions\ReopenPayrollAction;
use App\Domain\HR\Models\PayrollRun;
use App\Domain\HR\Services\HrCash;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Monthly payroll: calculate → review / edit payslips → approve → pay → EPF / ETF.
 */
class PayrollController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', PayrollRun::class);

        return view('hr.payroll.index', [
            'runs' => PayrollRun::query()->withCount('payslips')->orderByDesc('month')->paginate(24),
            'nextMonth' => $this->suggestedMonth(),
        ]);
    }

    public function store(Request $request, CalculatePayrollAction $calculate): RedirectResponse
    {
        $this->authorize('create', PayrollRun::class);

        $validated = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        if (PayrollRun::query()->where('month', $validated['month'])->exists()) {
            throw ValidationException::withMessages(['month' => 'That month is already in the list. Open it to recalculate.']);
        }

        $run = $calculate->handle($validated['month'], $request->user());

        return redirect()->route('hr.payroll.show', $run)->with('success', "Payroll for {$run->label()} calculated. Check each payslip, then approve.");
    }

    public function show(PayrollRun $payroll, DrawerCash $drawerCash, FinancePosting $posting): View
    {
        $this->authorize('view', $payroll);

        return view('hr.payroll.show', [
            'run' => $payroll->load(['createdBy', 'approvedBy', 'epfEtfPaidBy']),
            'payslips' => $payroll->payslips()->with(['employee', 'bankAccount', 'paidBy'])->orderBy('employee_name')->get(),
            'sources' => HrCash::sources(),
            'epfSources' => HrCash::sources(withDrawer: false),
            'banks' => BankAccount::options(),
            'drawerOpen' => $drawerCash->openSession() !== null,
            'entries' => $posting->entriesFor($payroll),
        ]);
    }

    public function recalculate(Request $request, PayrollRun $payroll, CalculatePayrollAction $calculate): RedirectResponse
    {
        $this->authorize('update', $payroll);

        $calculate->handle($payroll->month, $request->user());

        return back()->with('success', 'Recalculated from the latest attendance, advances and salaries. Your edits on the payslips were kept.');
    }

    public function approve(Request $request, PayrollRun $payroll, ApprovePayrollAction $approve): RedirectResponse
    {
        $this->authorize('update', $payroll);

        $approve->handle($payroll, $request->user());

        return back()->with('success', "Payroll for {$payroll->label()} approved. Rs. ".Money::format($payroll->refresh()->total_net).' is now owed to the staff.');
    }

    public function reopen(Request $request, PayrollRun $payroll, ReopenPayrollAction $reopen): RedirectResponse
    {
        $this->authorize('update', $payroll);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $reopen->handle($payroll, $request->user(), $validated['reason']);

        return back()->with('success', 'Payroll reopened. Correct it and approve again.');
    }

    public function destroy(PayrollRun $payroll): RedirectResponse
    {
        $this->authorize('update', $payroll);

        if (! $payroll->isEditable()) {
            throw ValidationException::withMessages(['payroll' => 'An approved payroll cannot be deleted. Reopen it first.']);
        }

        $payroll->delete();

        return redirect()->route('hr.payroll.index')->with('success', "Payroll for {$payroll->label()} deleted.");
    }

    public function pay(Request $request, PayrollRun $payroll, PaySalariesAction $pay): RedirectResponse
    {
        $this->authorize('update', $payroll);

        $validated = $request->validate([
            'payslips' => ['required', 'array', 'min:1'],
            'payslips.*' => ['integer'],
            'paid_from' => ['required', Rule::in(array_keys(HrCash::sources()))],
            'bank_account_id' => ['nullable', 'required_if:paid_from,bank', 'integer'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], ['payslips.required' => 'Tick the salaries to pay.']);

        if ($validated['paid_from'] === 'cash_drawer') {
            $validated['date'] = today()->toDateString();
        }

        $count = $pay->handle($payroll, array_map('intval', $validated['payslips']), $validated, $request->user());

        return back()->with('success', "{$count} ".str('salary')->plural($count).' paid.');
    }

    public function payEpfEtf(Request $request, PayrollRun $payroll, PayEpfEtfAction $pay): RedirectResponse
    {
        $this->authorize('update', $payroll);

        $validated = $request->validate([
            'paid_from' => ['required', Rule::in(array_keys(HrCash::sources(withDrawer: false)))],
            'bank_account_id' => ['nullable', 'required_if:paid_from,bank', 'integer'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $pay->handle($payroll, $validated, $request->user());

        return back()->with('success', "EPF and ETF for {$payroll->label()} recorded as paid.");
    }

    /**
     * Every payslip of the month, one per page.
     */
    public function pdf(Request $request, PayrollRun $payroll, Settings $settings): PdfBuilder
    {
        $this->authorize('view', $payroll);

        return Pdf::view('print.payslips-a4', [
            'run' => $payroll,
            'payslips' => $payroll->payslips()->with('lines')->orderBy('employee_name')->get(),
            'language' => $this->language($request),
            'shop' => $settings->group('shop'),
        ])->format('a4')->name("payslips-{$payroll->month}.pdf");
    }

    public static function language(Request $request): string
    {
        $language = (string) $request->query('lang', 'si+en');

        return in_array($language, ['si', 'en', 'si+en'], true) ? $language : 'si+en';
    }

    /**
     * Last month, unless it is already there, then this month.
     */
    private function suggestedMonth(): string
    {
        $last = today()->subMonthNoOverflow()->format('Y-m');

        return PayrollRun::query()->where('month', $last)->exists() ? today()->format('Y-m') : $last;
    }
}
