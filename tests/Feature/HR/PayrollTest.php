<?php

use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\HR\Actions\GiveSalaryAdvanceAction;
use App\Domain\HR\Enums\PayrollStatus;
use App\Domain\HR\Enums\SalaryAdvanceStatus;
use App\Domain\HR\Enums\SalaryComponentCalc;
use App\Domain\HR\Enums\SalaryComponentType;
use App\Domain\HR\Models\Attendance;
use App\Domain\HR\Models\Holiday;
use App\Domain\HR\Models\LeaveType;
use App\Domain\HR\Models\PayrollRun;
use App\Domain\HR\Models\Payslip;
use App\Domain\HR\Models\SalaryAdvance;
use App\Domain\HR\Models\SalaryComponent;
use App\Domain\HR\Services\LeaveService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\HrSeeder;
use Illuminate\Support\Carbon;

/*
| September 2026: 26 MonÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“Sat days, less the 24th (holiday) = 25 working days.
| Nimal: basic 50,000; absent on the 7th, no record on the 8th (2 no-pay days), paid leave on
| the 9th, 10 hours overtime; transport 3,000 (no EPF), attendance 2,000 (EPF), welfare 200
| deduction; a 10,000 advance over 4 months.
|
|   no-pay 50,000 ÃƒÆ’Ã¢â‚¬â€ 2 ÃƒÆ’Ã‚Â· 25 = 4,000     OT 50,000 ÃƒÆ’Ã¢â‚¬â€ 1.5 ÃƒÆ’Ã‚Â· 240 = 312.50 ÃƒÆ’Ã¢â‚¬â€ 10 h = 3,125
|   gross 50,000 ÃƒÂ¢Ã‹â€ Ã¢â‚¬â„¢ 4,000 + 5,000 + 3,125 = 54,125
|   EPF base 46,000 + 2,000 = 48,000 ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ 8 % 3,840 Ãƒâ€šÃ‚Â· 12 % 5,760 Ãƒâ€šÃ‚Â· ETF 3 % 1,440
|   net 54,125 ÃƒÂ¢Ã‹â€ Ã¢â‚¬â„¢ 3,840 ÃƒÂ¢Ã‹â€ Ã¢â‚¬â„¢ 2,500 ÃƒÂ¢Ã‹â€ Ã¢â‚¬â„¢ 200 = 47,585
*/
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
    $this->pos = posSetup();
    $this->seed([ChartOfAccountsSeeder::class, HrSeeder::class]);
    Holiday::create(['date' => '2026-09-24', 'name' => 'Binara Poya']);

    $component = fn (string $name, SalaryComponentType $type, string $value, bool $epf = false) => SalaryComponent::create(['name' => $name, 'type' => $type, 'calc' => SalaryComponentCalc::Fixed, 'value' => $value, 'is_epf_applicable' => $epf, 'is_active' => true]);
    $transport = $component('Transport', SalaryComponentType::Allowance, '3000');
    $attendance = $component('Attendance allowance', SalaryComponentType::Allowance, '2000', true);
    $welfare = $component('Welfare society', SalaryComponentType::Deduction, '200');

    $this->nimal = employee(['full_name' => 'Nimal Bandara', 'basic_salary' => '50000', 'epf_no' => '102'], $this->pos['staff'], [
        $transport->id => ['assigned' => true],
        $attendance->id => ['assigned' => true],
        $welfare->id => ['assigned' => true],
    ]);
    $this->kamala = employee(['full_name' => 'Kamala Herath', 'basic_salary' => '30000', 'is_epf_member' => false]);

    presentAllMonth($this->nimal, '2026-09', ['2026-09-07' => 'absent', '2026-09-08' => null, '2026-09-09' => null]);
    presentAllMonth($this->kamala, '2026-09');
    Attendance::where('employee_id', $this->nimal->id)->whereDate('date', '2026-09-10')->update(['ot_minutes' => 600]);

    $leave = app(LeaveService::class);
    $leave->request(['employee_id' => $this->nimal->id, 'leave_type_id' => LeaveType::firstWhere('name', 'Annual leave')->id, 'from_date' => '2026-09-09'], $this->pos['owner'], approve: true);

    $this->advance = app(GiveSalaryAdvanceAction::class)->handle([
        'employee_id' => $this->nimal->id, 'date' => '2026-09-15', 'amount' => '10000', 'installments' => 4, 'paid_from' => 'safe',
    ], $this->pos['owner']);

    $this->bank = bankAccount(['opening_balance' => '100000']);
});

afterEach(function () {
    expectBooksBalance();
});

function calculateSeptember(): PayrollRun
{
    test()->actingAs(test()->pos['owner'])->post(route('hr.payroll.store'), ['month' => '2026-09'])->assertSessionHasNoErrors()->assertRedirect();

    return PayrollRun::where('month', '2026-09')->sole();
}

function ledgerBalance(SystemAccount $account): string
{
    return (string) app(ChartOfAccounts::class)->get($account)->balance();
}

test('the payroll is calculated from attendance, leave, overtime, allowances, EPF and the advance', function () {
    $run = calculateSeptember();
    $slip = Payslip::where('employee_id', $this->nimal->id)->sole();

    expect($run->status)->toBe(PayrollStatus::Calculated)
        ->and((string) $slip->working_days)->toBe('25.0')
        ->and((string) $slip->days_worked)->toBe('22.0')
        ->and((string) $slip->paid_leave_days)->toBe('1.0')
        ->and((string) $slip->no_pay_days)->toBe('2.0')
        ->and($slip->no_pay_deduction)->toBe('4000.00')
        ->and($slip->allowances)->toBe('5000.00')
        ->and((string) $slip->ot_hours)->toBe('10.00')
        ->and($slip->ot_rate)->toBe('312.50')
        ->and($slip->ot_amount)->toBe('3125.00')
        ->and($slip->gross)->toBe('54125.00')
        ->and($slip->epf_base)->toBe('48000.00')
        ->and($slip->epf_employee)->toBe('3840.00')
        ->and($slip->epf_employer)->toBe('5760.00')
        ->and($slip->etf)->toBe('1440.00')
        ->and($slip->advance_deduction)->toBe('2500.00')
        ->and($slip->other_deductions)->toBe('200.00')
        ->and($slip->net)->toBe('47585.00');

    $kamala = Payslip::where('employee_id', $this->kamala->id)->sole();
    expect($kamala->gross)->toBe('30000.00')
        ->and($kamala->epf_employee)->toBe('0.00')
        ->and($kamala->etf)->toBe('0.00')
        ->and($kamala->net)->toBe('30000.00')
        ->and($run->total_net)->toBe('77585.00');

    // Nothing is posted before approval.
    expect(JournalEntry::where('event', 'payroll.approved')->exists())->toBeFalse();
});

test('someone who joined mid-month is not paid for the days before', function () {
    $new = employee(['full_name' => 'Ruwan', 'basic_salary' => '30000', 'join_date' => '2026-09-16', 'is_epf_member' => false]);
    presentAllMonth($new, '2026-09', collect(range(1, 15))->mapWithKeys(fn ($day) => [sprintf('2026-09-%02d', $day) => null])->all());

    calculateSeptember();
    $slip = Payslip::where('employee_id', $new->id)->sole();

    // 16thÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“30th: 12 working days of 25 ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ 13 no-pay days.
    expect((string) $slip->no_pay_days)->toBe('13.0')
        ->and($slip->no_pay_deduction)->toBe('15600.00')
        ->and($slip->gross)->toBe('14400.00');
});

test('approving posts the salaries and takes the advance installment', function () {
    $run = calculateSeptember();
    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.approve', $run))->assertSessionHasNoErrors();

    $entry = JournalEntry::where('event', 'payroll.approved')->sole();
    expect($run->refresh()->status)->toBe(PayrollStatus::Approved)
        ->and((string) $entry->lines->sum('debit'))->toBe('91125')
        ->and(ledgerBalance(SystemAccount::Salaries))->toBe('83925.00')
        ->and(ledgerBalance(SystemAccount::EpfEtfExpense))->toBe('7200.00')
        ->and(ledgerBalance(SystemAccount::EpfPayable))->toBe('9600.00')
        ->and(ledgerBalance(SystemAccount::EtfPayable))->toBe('1440.00')
        ->and(ledgerBalance(SystemAccount::SalariesPayable))->toBe('77585.00')
        ->and(ledgerBalance(SystemAccount::StaffAdvances))->toBe('7500.00')
        ->and($this->advance->refresh()->recovered_amount)->toBe('2500.00')
        ->and($this->advance->status)->toBe(SalaryAdvanceStatus::Active);

    // Approved payslips cannot be changed or recalculated.
    $slip = Payslip::where('employee_id', $this->nimal->id)->sole();
    $this->actingAs($this->pos['owner'])->put(route('hr.payslips.update', $slip), ['no_pay_days' => '0'])->assertSessionHasErrors('payslip');
    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.recalculate', $run))->assertSessionHasErrors('month');
});

test('salaries are paid from the bank and the cash at home; EPF and ETF are sent to the funds', function () {
    $run = calculateSeptember();
    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.approve', $run));
    $nimal = Payslip::where('employee_id', $this->nimal->id)->sole();
    $kamala = Payslip::where('employee_id', $this->kamala->id)->sole();

    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.pay', $run), [
        'payslips' => [$nimal->id], 'paid_from' => 'bank', 'bank_account_id' => $this->bank->id, 'date' => '2026-10-05', 'reference' => 'TRF-1',
    ])->assertSessionHasNoErrors();

    expect($run->refresh()->status)->toBe(PayrollStatus::Approved)
        ->and((string) $this->bank->balance())->toBe('52415.00');

    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.pay', $run), [
        'payslips' => [$nimal->id, $kamala->id], 'paid_from' => 'safe', 'date' => '2026-10-05',
    ])->assertSessionHasNoErrors();

    expect($run->refresh()->status)->toBe(PayrollStatus::Paid)
        ->and($kamala->refresh()->isPaid())->toBeTrue()
        ->and($nimal->refresh()->paid_from->value)->toBe('bank')
        ->and(ledgerBalance(SystemAccount::SalariesPayable))->toBe('0.00')
        ->and(JournalEntry::where('event', 'salary.paid')->count())->toBe(2);

    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.epf-etf', $run), [
        'paid_from' => 'bank', 'bank_account_id' => $this->bank->id, 'date' => '2026-10-05', 'reference' => 'EPF-09',
    ])->assertSessionHasNoErrors();

    expect(ledgerBalance(SystemAccount::EpfPayable))->toBe('0.00')
        ->and(ledgerBalance(SystemAccount::EtfPayable))->toBe('0.00')
        ->and((string) $this->bank->balance())->toBe('41375.00')
        ->and($run->refresh()->epf_etf_reference)->toBe('EPF-09');

    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.epf-etf', $run), ['paid_from' => 'safe', 'date' => '2026-10-05'])->assertSessionHasErrors('paid_from');
});

test('salaries paid from the drawer are a pay out of the open drawer', function () {
    $run = calculateSeptember();
    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.approve', $run));
    $session = openDrawer($this->pos['main'], $this->pos['owner'], 40);
    $kamala = Payslip::where('employee_id', $this->kamala->id)->sole();

    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.pay', $run), ['payslips' => [$kamala->id], 'paid_from' => 'cash_drawer', 'date' => '2026-10-01'])->assertSessionHasNoErrors();

    expect($kamala->refresh()->drawer_session_id)->toBe($session->id)
        ->and((string) app(DrawerCalculator::class)->expectedCash($session))->toBe('10000.00');
});

test('the owner edits a payslip before approval; recalculating keeps the edit', function () {
    $run = calculateSeptember();
    $slip = Payslip::where('employee_id', $this->nimal->id)->sole();

    $this->actingAs($this->pos['owner'])->put(route('hr.payslips.update', $slip), [
        'no_pay_days' => '0',
        'lines' => [['name' => 'Festival bonus', 'type' => 'allowance', 'amount' => '5000', 'epf' => '0']],
        'note' => 'Vesak bonus',
    ])->assertSessionHasNoErrors();

    // gross 50,000 + 5,000 + 5,000 + 3,125 = 63,125; EPF base 52,000 ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ 4,160; net 63,125 ÃƒÂ¢Ã‹â€ Ã¢â‚¬â„¢ 4,160 ÃƒÂ¢Ã‹â€ Ã¢â‚¬â„¢ 2,500 ÃƒÂ¢Ã‹â€ Ã¢â‚¬â„¢ 200.
    expect($slip->refresh()->gross)->toBe('63125.00')
        ->and($slip->epf_employee)->toBe('4160.00')
        ->and($slip->net)->toBe('56265.00')
        ->and($run->refresh()->total_net)->toBe('86265.00');

    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.recalculate', $run))->assertSessionHasNoErrors();
    expect($slip->refresh()->net)->toBe('56265.00')->and($slip->lines()->where('is_manual', true)->count())->toBe(1);

    $this->actingAs($this->pos['owner'])->get(route('hr.payslips.show', $slip))->assertOk()->assertSee('Festival bonus');
});

test('an approved payroll can be reopened until someone is paid', function () {
    $run = calculateSeptember();
    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.approve', $run));

    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.reopen', $run), ['reason' => 'Wrong OT'])->assertSessionHasNoErrors();

    expect($run->refresh()->status)->toBe(PayrollStatus::Calculated)
        ->and($this->advance->refresh()->recovered_amount)->toBe('0.00')
        ->and(ledgerBalance(SystemAccount::SalariesPayable))->toBe('0.00')
        ->and(ledgerBalance(SystemAccount::StaffAdvances))->toBe('10000.00');

    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.approve', $run))->assertSessionHasNoErrors();
    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.pay', $run), ['payslips' => [Payslip::first()->id], 'paid_from' => 'safe', 'date' => '2026-10-05']);
    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.reopen', $run), ['reason' => 'Again'])->assertSessionHasErrors('reason');
});

test('the owner can recover the whole advance at once; it is then recovered and stops', function () {
    $run = calculateSeptember();
    $slip = Payslip::where('employee_id', $this->nimal->id)->sole();

    $this->actingAs($this->pos['owner'])->put(route('hr.payslips.update', $slip), ['advance' => '15000'])->assertSessionHasNoErrors();
    expect($slip->refresh()->advance_deduction)->toBe('10000.00');

    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.approve', $run))->assertSessionHasNoErrors();
    expect($this->advance->refresh()->status)->toBe(SalaryAdvanceStatus::Recovered)
        ->and(ledgerBalance(SystemAccount::StaffAdvances))->toBe('0.00');

    // October: nothing left to recover.
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));
    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.store'), ['month' => '2026-10'])->assertSessionHasNoErrors();
    expect(Payslip::where('employee_id', $this->nimal->id)->whereHas('payrollRun', fn ($query) => $query->where('month', '2026-10'))->sole()->advance_deduction)->toBe('0.00');
});

test('a salary advance can be cancelled until an installment is taken', function () {
    $this->actingAs($this->pos['owner'])->post(route('hr.advances.cancel', $this->advance), ['reason' => 'Returned the money'])->assertSessionHasNoErrors();

    expect($this->advance->refresh()->status)->toBe(SalaryAdvanceStatus::Cancelled)
        ->and(ledgerBalance(SystemAccount::StaffAdvances))->toBe('0.00')
        ->and(ledgerBalance(SystemAccount::Safe))->toBe('0.00');

    calculateSeptember();
    expect(Payslip::where('employee_id', $this->nimal->id)->sole()->advance_deduction)->toBe('0.00');
});

test('a payroll month can only be run once and not for the future', function () {
    calculateSeptember();

    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.store'), ['month' => '2026-09'])->assertSessionHasErrors('month');
    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.store'), ['month' => '2026-11'])->assertSessionHasErrors('month');
});

test('a calculated payroll can be deleted; an approved one cannot', function () {
    $run = calculateSeptember();
    $this->actingAs($this->pos['owner'])->delete(route('hr.payroll.destroy', $run))->assertRedirect(route('hr.payroll.index'));
    expect(PayrollRun::count())->toBe(0)->and(Payslip::count())->toBe(0);

    $run = calculateSeptember();
    $this->actingAs($this->pos['owner'])->post(route('hr.payroll.approve', $run));
    $this->actingAs($this->pos['owner'])->delete(route('hr.payroll.destroy', $run))->assertSessionHasErrors('payroll');
});

test('advance given from the safe is posted to staff advances', function () {
    expect(ledgerBalance(SystemAccount::StaffAdvances))->toBe('10000.00')
        ->and(ledgerBalance(SystemAccount::Safe))->toBe('-10000.00')
        ->and($this->advance->installment_amount)->toBe('2500.00')
        ->and(SalaryAdvance::sole()->number)->toStartWith('ADV-');
});
