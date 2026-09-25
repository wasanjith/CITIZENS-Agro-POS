{{--
    A4 customer statement (PDF through headless Chrome). Data from CustomerStatement::build.
    Expects: $statement, $language, $shop.
--}}
@php
    $font = fn (int $weight) => base64_encode(file_get_contents(public_path("fonts/NotoSansSinhala-{$weight}.woff2")));
    $primary = $language === 'en' ? 'en' : 'si';
    $bilingual = $language === 'si+en';
    $t = fn (string $key) => __('receipt.'.$key, [], $primary).($bilingual ? ' / '.__('receipt.'.$key, [], 'en') : '');
    $money = fn ($amount) => number_format((float) $amount, 2);
    $customer = $statement['customer'];
@endphp
<!DOCTYPE html>
<html lang="{{ $primary }}">
<head>
    <meta charset="utf-8">
    <title>{{ $customer->code }}</title>
    <style>
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 400; src: url(data:font/woff2;base64,{{ $font(400) }}) format('woff2'); }
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 700; src: url(data:font/woff2;base64,{{ $font(700) }}) format('woff2'); }
        @page { size: A4; margin: 16mm; }
        body { font-family: 'Noto Sans Sinhala', sans-serif; font-size: 10.5pt; color: #111; }
        h1 { margin: 0; font-size: 18pt; }
        h2 { font-size: 12pt; margin: 7mm 0 2mm; border-bottom: 1px solid #999; padding-bottom: 1mm; }
        .muted { color: #555; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #16824f; padding-bottom: 5mm; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 9.5pt; border-bottom: 1px solid #999; padding: 1.5mm; }
        td { padding: 1.5mm; border-bottom: 1px solid #eee; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .strong td { font-weight: 700; border-top: 2px solid #111; }
        .meta td { border: 0; padding: 0.5mm 3mm 0.5mm 0; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ $primary === 'si' ? $shop['name_si'] : $shop['name_en'] }}</h1>
            <div class="muted">{{ $primary === 'si' ? ($shop['address_si'] ?: $shop['address_en']) : $shop['address_en'] }}</div>
            <div class="muted">{{ $shop['phone'] }}</div>
            <h2 style="border: 0; margin-top: 4mm">{{ $t('statement') }}</h2>
        </div>
        <table class="meta" style="width: auto">
            <tr><td class="muted">{{ $t('customer') }}</td><td>{{ $customer->name }}@if ($customer->name_si)<br>{{ $customer->name_si }}@endif</td></tr>
            <tr><td class="muted">{{ $t('customer_code') }}</td><td>{{ $customer->code }}</td></tr>
            @if ($customer->phone)<tr><td class="muted">{{ $t('phone') }}</td><td>{{ $customer->phone }}</td></tr>@endif
            <tr><td class="muted">{{ $t('period') }}</td><td>{{ $statement['from']->format('Y-m-d') }} – {{ $statement['to']->format('Y-m-d') }}</td></tr>
            <tr><td class="muted">{{ $t('credit_limit') }}</td><td>{{ $money($customer->credit_limit) }}</td></tr>
        </table>
    </div>

    <table style="margin-top: 5mm">
        <thead>
            <tr>
                <th>{{ $t('date') }}</th>
                <th>{{ $t('description') }}</th>
                <th>{{ $t('due_date') }}</th>
                <th class="num">{{ $t('debit') }}</th>
                <th class="num">{{ $t('credit_col') }}</th>
                <th class="num">{{ $t('running_balance') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr><td></td><td>{{ $t('opening_balance') }}</td><td></td><td></td><td></td><td class="num">{{ $money($statement['opening']) }}</td></tr>
            @foreach ($statement['rows'] as $row)
                <tr>
                    <td>{{ $row['date']->format('Y-m-d') }}</td>
                    <td>{{ $primary === 'si' ? $row['type']->labelSi() : $row['type']->label() }} · {{ $row['reference'] }}@if ($row['note'])<br><span class="muted">{{ $row['note'] }}</span>@endif</td>
                    <td>{{ $row['due_date']?->format('Y-m-d') }}</td>
                    <td class="num">{{ (float) $row['debit'] ? $money($row['debit']) : '' }}</td>
                    <td class="num">{{ (float) $row['credit'] ? $money($row['credit']) : '' }}</td>
                    <td class="num">{{ $money($row['balance']) }}</td>
                </tr>
            @endforeach
            <tr class="strong"><td></td><td>{{ $t('closing_balance') }}</td><td></td><td class="num">{{ $money($statement['total_debit']) }}</td><td class="num">{{ $money($statement['total_credit']) }}</td><td class="num">{{ $money($statement['closing']) }}</td></tr>
        </tbody>
    </table>

    @if ($statement['open_invoices']->isNotEmpty())
        <h2>{{ $t('to_pay') }}</h2>
        <table>
            <thead><tr><th>{{ $t('invoice_no') }}</th><th>{{ $t('date') }}</th><th>{{ $t('due_date') }}</th><th class="num">{{ $t('total') }}</th><th class="num">{{ $t('to_pay') }}</th></tr></thead>
            <tbody>
                @foreach ($statement['open_invoices'] as $sale)
                    <tr>
                        <td>{{ $sale->invoice_no }}@if ($sale->isOverdue()) <strong>({{ $t('overdue') }})</strong>@endif</td>
                        <td>{{ $sale->settled_at?->format('Y-m-d') }}</td>
                        <td>{{ $sale->due_date?->format('Y-m-d') }}</td>
                        <td class="num">{{ $money($sale->total) }}</td>
                        <td class="num">{{ $money($sale->balance_due) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="muted" style="margin-top: 8mm">{{ now()->format('Y-m-d H:i') }}</p>
</body>
</html>
