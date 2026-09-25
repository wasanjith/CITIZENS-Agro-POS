{{--
    A4 invoice (and the printing-test sample) rendered to PDF by headless Chrome (spatie/laravel-pdf, chrome driver).
    The Sinhala font is embedded as base64 so the PDF renders the same on any server.
--}}
@php
    $font = fn (int $weight) => base64_encode(file_get_contents(public_path("fonts/NotoSansSinhala-{$weight}.woff2")));
    $primary = $language === 'en' ? 'en' : 'si';
    $bilingual = $language === 'si+en';
    $t = fn (string $key) => __('receipt.'.$key, [], $primary).($bilingual ? ' / '.__('receipt.'.$key, [], 'en') : '');
    $money = fn (float $amount) => number_format($amount, 2);
@endphp
<!DOCTYPE html>
<html lang="{{ $primary }}">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 400; src: url(data:font/woff2;base64,{{ $font(400) }}) format('woff2'); }
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 700; src: url(data:font/woff2;base64,{{ $font(700) }}) format('woff2'); }
        @page { size: A4; margin: 18mm 16mm; }
        body { font-family: 'Noto Sans Sinhala', sans-serif; font-size: 11pt; color: #111; }
        h1 { margin: 0; font-size: 20pt; }
        .muted { color: #555; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #16824f; padding-bottom: 8mm; }
        .meta td { padding: 1mm 4mm 1mm 0; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8mm; }
        table.items th { text-align: left; border-bottom: 1px solid #999; padding: 2mm; font-size: 10pt; }
        table.items td { padding: 2mm; border-bottom: 1px solid #eee; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .totals { margin-left: auto; margin-top: 6mm; width: 45%; }
        .totals td { padding: 1.5mm 2mm; }
        .grand td { font-weight: 700; font-size: 13pt; border-top: 2px solid #111; }
        .sample { color: #b91c1c; font-weight: 700; border: 2px solid #b91c1c; padding: 1mm 3mm; display: inline-block; margin-top: 3mm; }
        .footer { margin-top: 12mm; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ $primary === 'si' ? $shop['name_si'] : $shop['name_en'] }}</h1>
            @if ($bilingual)<div>{{ $shop['name_en'] }}</div>@endif
            <div class="muted">{{ $primary === 'si' ? ($shop['address_si'] ?: $shop['address_en']) : $shop['address_en'] }}</div>
            <div class="muted">{{ $shop['phone'] }}</div>
            @if (! empty($invoice['is_sample']))<div class="sample">{{ $t('sample') }}</div>@endif
            @if (! empty($invoice['is_copy']))<div class="sample">{{ __('receipt.copy', [], 'si') }} / {{ __('receipt.copy', [], 'en') }}</div>@endif
            @if (! empty($invoice['is_void']))<div class="sample">{{ $t('void') }}</div>@endif
        </div>
        <table class="meta">
            <tr><td class="muted">{{ $t('invoice_no') }}</td><td>{{ $invoice['number'] }}</td></tr>
            <tr><td class="muted">{{ $t('date') }}</td><td>{{ $invoice['date']->format('Y-m-d H:i') }}</td></tr>
            <tr><td class="muted">{{ $t('counter') }}</td><td>{{ $invoice['counter'] }}</td></tr>
            <tr><td class="muted">{{ $t('staff') }}</td><td>{{ $invoice['staff'] }}</td></tr>
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>{{ $t('item') }}</th>
                <th class="num">{{ $t('qty') }}</th>
                <th class="num">{{ $t('price') }}</th>
                <th class="num">{{ $t('amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice['items'] as $item)
                <tr>
                    <td>{{ $primary === 'si' ? ($item['name_si'] ?: $item['name']) : $item['name'] }}@if ($bilingual)<br><span class="muted">{{ $item['name'] }}</span>@endif</td>
                    <td class="num">{{ rtrim(rtrim(number_format($item['qty'], 3, '.', ''), '0'), '.') }} {{ $primary === 'si' ? $item['unit_si'] : $item['unit'] }}</td>
                    <td class="num">{{ $money($item['price']) }}</td>
                    <td class="num">{{ $money($item['total'] + ($item['discount'] ?? 0)) }}@if (($item['discount'] ?? 0) > 0)<br><span class="muted">-{{ $money($item['discount']) }}</span>@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>{{ $t('subtotal') }}</td><td class="num">{{ $money($invoice['subtotal']) }}</td></tr>
        @if ($invoice['discount'] > 0)
            <tr><td>{{ $t('discount') }}</td><td class="num">-{{ $money($invoice['discount']) }}</td></tr>
        @endif
        <tr class="grand"><td>{{ $t('total') }}</td><td class="num">{{ $money($invoice['total']) }}</td></tr>
        @if ($invoice['is_cash'] ?? true)
            <tr><td>{{ $t('paid_cash') }}</td><td class="num">{{ $money($invoice['tendered']) }}</td></tr>
            <tr><td>{{ $t('balance') }}</td><td class="num">{{ $money($invoice['balance']) }}</td></tr>
        @else
            <tr><td>{{ $t('paid') }} ({{ $t('method_'.$invoice['method']) }})</td><td class="num">{{ $money($invoice['tendered']) }}</td></tr>
        @endif
    </table>

    <div class="footer muted">{{ $primary === 'si' ? $receipt['footer_si'] : $receipt['footer_en'] }}</div>
</body>
</html>
