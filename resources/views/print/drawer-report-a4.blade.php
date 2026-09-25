{{-- A4 Z report / handover report (PDF through headless Chrome). Same data as print.drawer-report. --}}
@php
    $font = fn (int $weight) => base64_encode(file_get_contents(public_path("fonts/NotoSansSinhala-{$weight}.woff2")));
    $primary = $language === 'en' ? 'en' : 'si';
    $bilingual = $language === 'si+en';
    $t = fn (string $key) => __('receipt.'.$key, [], $primary).($bilingual ? ' / '.__('receipt.'.$key, [], 'en') : '');
    $money = fn ($amount) => number_format((float) $amount, 2);
    $title = match ($kind) { 'z' => $t('z_report'), 'handover' => $t('handover_slip'), default => 'X' };
@endphp
<!DOCTYPE html>
<html lang="{{ $primary }}">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 400; src: url(data:font/woff2;base64,{{ $font(400) }}) format('woff2'); }
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 700; src: url(data:font/woff2;base64,{{ $font(700) }}) format('woff2'); }
        @page { size: A4; margin: 16mm; }
        body { font-family: 'Noto Sans Sinhala', sans-serif; font-size: 10.5pt; color: #111; }
        h1 { margin: 0; font-size: 18pt; }
        h2 { font-size: 12pt; margin: 7mm 0 2mm; border-bottom: 1px solid #999; padding-bottom: 1mm; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 9.5pt; border-bottom: 1px solid #999; padding: 1.5mm; }
        td { padding: 1.5mm; border-bottom: 1px solid #eee; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .strong td { font-weight: 700; border-top: 2px solid #111; }
    </style>
</head>
<body>
    <h1>{{ $primary === 'si' ? $shop['name_si'] : $shop['name_en'] }} · {{ $title }}</h1>
    <p class="muted">{{ $report['terminal'] }} · {{ $t('from') }} {{ $report['from']->format('Y-m-d H:i') }} · {{ $t('to') }} {{ $report['to']->format('Y-m-d H:i') }}</p>

    <h2>{{ $t('holder') }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ $t('holder') }}</th>
                <th>{{ $t('from') }} – {{ $t('to') }}</th>
                <th class="num">{{ $t('opening_float') }}</th>
                <th class="num">{{ $t('cash_sales') }}</th>
                <th class="num">{{ $t('pay_in') }} / {{ $t('pay_out') }}</th>
                <th class="num">{{ $t('expected_cash') }}</th>
                <th class="num">{{ $t('counted_cash') }}</th>
                <th class="num">{{ $t('variance') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report['sessions'] as $session)
                @php $m = $session['summary']['movements']; @endphp
                <tr>
                    <td>{{ $session['holder'] }}@if ($session['handed_to'])<br><span class="muted">→ {{ $session['handed_to'] }}</span>@endif</td>
                    <td>{{ $session['opened_at']->format('H:i') }} – {{ $session['closed_at']?->format('H:i') ?? '…' }}<br><span class="muted">{{ $session['close_reason'] }}</span></td>
                    <td class="num">{{ $money($session['summary']['opening_float']) }}</td>
                    <td class="num">{{ $money($session['summary']['cash_sales']) }}</td>
                    <td class="num">+{{ $money($m['pay_in']['amount']) }} / -{{ $money((float) $m['pay_out']['amount'] + (float) $m['safe_drop']['amount'] + (float) $m['bank_deposit']['amount']) }}</td>
                    <td class="num">{{ $money($session['expected_cash'] ?? $session['summary']['expected_cash']) }}</td>
                    <td class="num">{{ $session['counted_cash'] !== null ? $money($session['counted_cash']) : '—' }}</td>
                    <td class="num">{{ $session['variance'] !== null ? $money($session['variance']) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>{{ $t('sales_by_counter') }}</h2>
    <table>
        <thead><tr><th>{{ $t('counter') }}</th><th class="num">{{ $t('invoices') }}</th><th class="num">{{ $t('total') }}</th></tr></thead>
        <tbody>
            @foreach ($report['per_counter'] as $row)
                <tr><td>{{ $row['terminal'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ $money($row['total']) }}</td></tr>
            @endforeach
            <tr class="strong"><td>{{ $t('sales_total') }}</td><td class="num">{{ $report['sales_count'] }}</td><td class="num">{{ $money($report['sales_total']) }}</td></tr>
        </tbody>
    </table>

    <h2>{{ $t('payments_by_method') }}</h2>
    <table>
        <tbody>
            @foreach ($report['by_method'] as $method => $row)
                <tr><td>{{ $t('method_'.$method) }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ $money($row['amount']) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <h2>{{ $t('voids') }} ({{ $report['void_count'] }})</h2>
    <table>
        <tbody>
            @forelse ($report['voids'] as $row)
                <tr><td>{{ $row['terminal'] }}</td><td>{{ implode(', ', $row['numbers']) }}</td><td class="num">{{ $money($row['total']) }}</td></tr>
            @empty
                <tr><td class="muted">—</td></tr>
            @endforelse
        </tbody>
    </table>

    @if (count($report['sessions']) > 1)
        <p><strong>{{ $t('variance') }}:</strong> {{ $money($report['total_variance']) }}</p>
    @endif
</body>
</html>
