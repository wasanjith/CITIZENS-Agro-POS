<x-ui.tabs class="mb-6" :active="$active" :tabs="[
    'attendance' => ['label' => 'Attendance summary', 'href' => route('hr.reports.attendance')],
    'payroll' => ['label' => 'Payroll summary', 'href' => route('hr.reports.payroll')],
    'epf-etf' => ['label' => 'EPF / ETF', 'href' => route('hr.reports.epf-etf')],
]" />
