<?php

use App\Domain\HR\Actions\ApprovePayrollAction;
use App\Domain\HR\Actions\CalculatePayrollAction;
use App\Domain\HR\Actions\GiveSalaryAdvanceAction;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\PayrollRun;
use App\Domain\HR\Models\Payslip;
use App\Domain\HR\Models\SalaryComponent;
use App\Domain\Identity\Actions\CreateDelegationAction;
use App\Domain\Identity\Exceptions\NonDelegablePermissionException;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\HrSeeder;
use Illuminate\Support\Carbon;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
    $this->pos = posSetup();
    $this->seed([ChartOfAccountsSeeder::class, HrSeeder::class]);
    // A salary component on the pages catches Blade's own $component variable being shadowed.
    $transport = SalaryComponent::create(['name' => 'Transport', 'type' => 'allowance', 'calc' => 'fixed', 'value' => '3000', 'is_active' => true]);
    $this->nimal = employee(['full_name' => 'Nimal Bandara', 'name_si' => 'නිමල් බණ්ඩාර'], $this->pos['staff'], [$transport->id => ['assigned' => true]]);
    presentAllMonth($this->nimal, '2026-09');
    $this->advance = app(GiveSalaryAdvanceAction::class)->handle(['employee_id' => $this->nimal->id, 'date' => '2026-09-15', 'amount' => '6000', 'installments' => 3, 'paid_from' => 'safe'], $this->pos['owner']);
    $this->run = app(CalculatePayrollAction::class)->handle('2026-09', $this->pos['owner']);
    $this->payslip = Payslip::sole();
});

test('the owner sees every HR page', function (string $route, Closure $params) {
    $this->actingAs($this->pos['owner'])->get(route($route, $params()))->assertOk();
})->with([
    ['hr.employees.index', fn () => []],
    ['hr.employees.create', fn () => []],
    ['hr.employees.show', fn () => [test()->nimal]],
    ['hr.employees.edit', fn () => [test()->nimal]],
    ['hr.attendance.index', fn () => ['date' => '2026-09-30']],
    ['hr.attendance.month', fn () => ['month' => '2026-09']],
    ['hr.attendance.mine', fn () => []],
    ['hr.leave.index', fn () => []],
    ['hr.setup.index', fn () => []],
    ['hr.components.index', fn () => []],
    ['hr.advances.index', fn () => []],
    ['hr.advances.create', fn () => []],
    ['hr.advances.show', fn () => [test()->advance]],
    ['hr.payroll.index', fn () => []],
    ['hr.payroll.show', fn () => [test()->run]],
    ['hr.payslips.show', fn () => [test()->payslip]],
    ['hr.reports.attendance', fn () => ['month' => '2026-09']],
    ['hr.reports.payroll', fn () => ['year' => 2026]],
    ['hr.reports.epf-etf', fn () => ['month' => '2026-09']],
    ['admin.settings.edit', fn () => ['group' => 'hr']],
]);

test('an approved payroll page shows the pay form; the payslip PDF prints in Sinhala', function () {
    app(ApprovePayrollAction::class)->handle($this->run, $this->pos['owner']);

    $this->actingAs($this->pos['owner'])->get(route('hr.payroll.show', $this->run))->assertOk()->assertSee('Pay salaries');

    Pdf::fake();
    $this->actingAs($this->pos['owner'])->get(route('hr.payslips.pdf', ['payslip' => $this->payslip, 'lang' => 'si']))->assertOk();
    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf) => $pdf->viewName === 'print.payslips-a4'
        && str_contains($pdf->getHtml(), 'නිමල් බණ්ඩාර')
        && str_contains($pdf->getHtml(), 'ශුද්ධ වැටුප'));

    $this->actingAs($this->pos['owner'])->get(route('hr.payroll.pdf', $this->run))->assertOk();
    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf) => str_contains($pdf->getHtml(), 'Net pay') && str_contains($pdf->getHtml(), 'ශුද්ධ වැටුප'));
});

test('the owner adds an employee with a login, salary components and their own amount', function () {
    $transport = SalaryComponent::firstWhere('name', 'Transport');

    $this->actingAs($this->pos['owner'])->post(route('hr.employees.store'), [
        'full_name' => 'Kamala Herath',
        'nic' => '851234567v',
        'join_date' => '2026-10-01',
        'employment_type' => 'probation',
        'basic_salary' => '35000',
        'is_epf_member' => '1',
        'is_active' => '1',
        'user_id' => $this->pos['manager']->id,
        'components' => [$transport->id => ['assigned' => '1', 'value' => '2500']],
    ])->assertSessionHasNoErrors();

    $kamala = Employee::firstWhere('full_name', 'Kamala Herath');
    expect($kamala->code)->toStartWith('E-')
        ->and($kamala->nic)->toBe('851234567V')
        ->and($this->pos['manager']->refresh()->employee_id)->toBe($kamala->id)
        ->and($kamala->salaryComponents->first()->pivot->value_override)->toBe('2500.00');

    // A login belongs to one employee only.
    $this->actingAs($this->pos['owner'])->post(route('hr.employees.store'), [
        'full_name' => 'Someone', 'join_date' => '2026-10-01', 'employment_type' => 'casual', 'basic_salary' => '1000', 'user_id' => $this->pos['staff']->id,
    ])->assertSessionHasErrors('user_id');
});

test('the Manager and staff cannot open employees or payroll', function () {
    foreach (['hr.employees.index', 'hr.payroll.index', 'hr.advances.index', 'hr.components.index', 'hr.setup.index', 'hr.reports.payroll'] as $route) {
        $this->actingAs($this->pos['manager'])->get(route($route))->assertForbidden();
        $this->actingAs($this->pos['staff'])->get(route($route))->assertForbidden();
    }

    $this->actingAs($this->pos['manager'])->get(route('hr.payslips.show', $this->payslip))->assertForbidden();
    $this->actingAs($this->pos['manager'])->post(route('hr.payroll.approve', $this->run))->assertForbidden();
});

test('payroll can never be handed over to the Manager', function () {
    app(CreateDelegationAction::class)->handle($this->pos['owner'], $this->pos['manager'], now()->addHours(3), ['pos.settle', 'hr.payroll.manage']);
})->throws(NonDelegablePermissionException::class);

test('a Manager holding cashier authority still cannot open payroll pages', function () {
    app(CreateDelegationAction::class)->handle($this->pos['owner'], $this->pos['manager'], now()->addHours(3), ['pos.settle', 'drawer.manage', 'customers.credit.manage']);

    $this->actingAs($this->pos['manager'])->get(route('hr.payroll.index'))->assertForbidden();
    $this->actingAs($this->pos['manager'])->get(route('hr.payroll.show', $this->run))->assertForbidden();
    $this->actingAs($this->pos['manager'])->get(route('hr.employees.index'))->assertForbidden();
    expect(PayrollRun::sole()->isEditable())->toBeTrue();
});

test('the menu shows HR pages by role', function () {
    $this->actingAs($this->pos['owner'])->get(route('dashboard'))->assertSee('Payroll')->assertSee('Employees');
    $this->actingAs($this->pos['manager'])->get(route('dashboard'))->assertSee('Attendance')->assertDontSee('Salary advances');
    $this->actingAs($this->pos['staff'])->get(route('dashboard'))->assertSee('My attendance')->assertDontSee('Employees');
});
