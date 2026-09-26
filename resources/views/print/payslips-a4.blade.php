{{-- A4 payslips (PDF through headless Chrome), two to a page. $language: si, en or si+en. --}}
@php
    $font = fn (int $weight) => base64_encode(file_get_contents(public_path("fonts/NotoSansSinhala-{$weight}.woff2")));
    $primary = $language === 'en' ? 'en' : 'si';
    $bilingual = $language === 'si+en';
    $t = fn (string $key) => __('payslip.'.$key, [], $primary).($bilingual ? ' / '.__('payslip.'.$key, [], 'en') : '');
    $money = fn ($amount) => number_format((float) $amount, 2);
@endphp
<!DOCTYPE html>
<html lang="{{ $primary }}">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 400; src: url(data:font/woff2;base64,{{ $font(400) }}) format('woff2'); }
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 700; src: url(data:font/woff2;base64,{{ $font(700) }}) format('woff2'); }
        @page { size: A4; margin: 12mm; }
        body { font-family: 'Noto Sans Sinhala', sans-serif; font-size: 9.5pt; color: #111; margin: 0; }
        .slip { border: 1px solid #999; padding: 5mm; margin-bottom: 8mm; page-break-inside: avoid; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 2mm; }
        h1 { margin: 0; font-size: 13pt; }
        h2 { margin: 0; font-size: 11pt; text-align: right; }
        .muted { color: #555; }
        .who { display: grid; grid-template-columns: 1fr 1fr; gap: 1mm 6mm; margin: 3mm 0; }
        .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 9pt; border-bottom: 1px solid #999; padding: 1mm; }
        td { padding: 1mm; border-bottom: 1px solid #eee; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .total td { font-weight: 700; border-top: 1px solid #111; }
        .net { margin-top: 3mm; padding: 2mm 3mm; border: 2px solid #111; display: flex; justify-content: space-between; font-size: 12pt; font-weight: 700; }
        .foot { display: flex; justify-content: space-between; margin-top: 8mm; font-size: 8.5pt; }
        .sign { border-top: 1px dotted #111; width: 60mm; padding-top: 1mm; text-align: center; }
    </style>
</head>
<body>
    @foreach ($payslips as $payslip)
        @php
            $name = $primary === 'si' && $payslip->employee_name_si ? $payslip->employee_name_si : $payslip->employee_name;
            $allowances = $payslip->lines->where('type', \App\Domain\HR\Enums\SalaryComponentType::Allowance);
            $deductions = $payslip->lines->where('type', \App\Domain\HR\Enums\SalaryComponentType::Deduction);
        @endphp
        <div class="slip">
            <div class="head">
                <div>
                    <h1>{{ $primary === 'si' ? $shop['name_si'] : $shop['name_en'] }}</h1>
                    <span class="muted">{{ $primary === 'si' ? ($shop['address_si'] ?: $shop['address_en']) : $shop['address_en'] }}</span>
                </div>
                <h2>{{ $t('payslip') }}<br><span class="muted">{{ $run->start()->format('Y F') }}</span></h2>
            </div>

            <div class="who">
                <div>{{ $t('employee') }}: <strong>{{ $name }}</strong>@if ($bilingual && $payslip->employee_name_si && $name !== $payslip->employee_name) ({{ $payslip->employee_name }})@endif</div>
                <div>{{ $t('designation') }}: {{ $payslip->designation ?: '—' }}</div>
                <div>{{ $t('epf_no') }}: {{ $payslip->is_epf_member ? ($payslip->epf_no ?: '—') : '—' }}</div>
                <div>{{ $t('working_days') }}: {{ $payslip->working_days }} · {{ $t('days_worked') }}: {{ $payslip->days_worked }}@if ((float) $payslip->paid_leave_days) · {{ $t('paid_leave') }}: {{ $payslip->paid_leave_days }}@endif @if ((float) $payslip->no_pay_days) · {{ $t('no_pay_days') }}: {{ $payslip->no_pay_days }}@endif</div>
            </div>

            <div class="cols">
                <table>
                    <thead><tr><th>{{ $t('earnings') }}</th><th class="num"></th></tr></thead>
                    <tbody>
                        <tr><td>{{ $t('basic') }}</td><td class="num">{{ $money($payslip->basic) }}</td></tr>
                        @if ((float) $payslip->no_pay_deduction)
                            <tr><td>{{ $t('no_pay') }} ({{ $payslip->no_pay_days }})</td><td class="num">-{{ $money($payslip->no_pay_deduction) }}</td></tr>
                        @endif
                        @foreach ($allowances as $line)
                            <tr><td>{{ $line->component_name }}</td><td class="num">{{ $money($line->amount) }}</td></tr>
                        @endforeach
                        @if ((float) $payslip->ot_amount)
                            <tr><td>{{ $t('overtime') }} ({{ $payslip->ot_hours }} {{ __('payslip.hours', [], $primary) }} × {{ $money($payslip->ot_rate) }})</td><td class="num">{{ $money($payslip->ot_amount) }}</td></tr>
                        @endif
                        <tr class="total"><td>{{ $t('gross') }}</td><td class="num">{{ $money($payslip->gross) }}</td></tr>
                    </tbody>
                </table>

                <table>
                    <thead><tr><th>{{ $t('deductions') }}</th><th class="num"></th></tr></thead>
                    <tbody>
                        @if ($payslip->is_epf_member)
                            <tr><td>{{ $t('epf_employee') }}</td><td class="num">{{ $money($payslip->epf_employee) }}</td></tr>
                        @endif
                        @if ((float) $payslip->advance_deduction)
                            <tr><td>{{ $t('advance') }}</td><td class="num">{{ $money($payslip->advance_deduction) }}</td></tr>
                        @endif
                        @foreach ($deductions as $line)
                            <tr><td>{{ $line->component_name }}</td><td class="num">{{ $money($line->amount) }}</td></tr>
                        @endforeach
                        <tr class="total"><td>{{ $t('total_deductions') }}</td><td class="num">{{ $money($payslip->total_deductions) }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="net"><span>{{ $t('net') }}</span><span>Rs. {{ $money($payslip->net) }}</span></div>

            @if ($payslip->is_epf_member)
                <p class="muted" style="margin: 2mm 0 0;">{{ $t('employer') }}: {{ $t('epf_employer') }} {{ $money($payslip->epf_employer) }} · {{ $t('etf') }} {{ $money($payslip->etf) }}</p>
            @endif
            @if ($payslip->note)
                <p class="muted" style="margin: 1mm 0 0;">{{ $t('note') }}: {{ $payslip->note }}</p>
            @endif

            <div class="foot">
                <span>{{ $t('paid_on') }}: {{ $payslip->paid_at?->format('Y-m-d') ?? '……………………' }}</span>
                <span class="sign">{{ $t('signature') }}</span>
            </div>
        </div>
    @endforeach
</body>
</html>
