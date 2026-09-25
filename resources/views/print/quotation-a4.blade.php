{{--
    A4 quotation (PDF through headless Chrome). Expects: $quotation (with lines, customer, creator), $language, $shop.
--}}
@php
    $font = fn (int $weight) => base64_encode(file_get_contents(public_path("fonts/NotoSansSinhala-{$weight}.woff2")));
    $primary = $language === 'en' ? 'en' : 'si';
    $bilingual = $language === 'si+en';
    $t = fn (string $key) => __('receipt.'.$key, [], $primary).($bilingual ? ' / '.__('receipt.'.$key, [], 'en') : '');
    $money = fn ($amount) => number_format((float) $amount, 2);
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    $discount = (float) $quotation->line_discount_total + (float) $quotation->bill_discount;
@endphp
<!DOCTYPE html>
<html lang="{{ $primary }}">
<head>
    <meta charset="utf-8">
    <title>{{ $quotation->number }}</title>
    <style>
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 400; src: url(data:font/woff2;base64,{{ $font(400) }}) format('woff2'); }
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 700; src: url(data:font/woff2;base64,{{ $font(700) }}) format('woff2'); }
        @page { size: A4; margin: 18mm 16mm; }
        body { font-family: 'Noto Sans Sinhala', sans-serif; font-size: 11pt; color: #111; }
        h1 { margin: 0; font-size: 20pt; }
        .muted { color: #555; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #16824f; padding-bottom: 8mm; }
        .meta td { padding: 1mm 4mm 1mm 0; }
        .title { font-size: 15pt; font-weight: 700; margin-top: 3mm; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8mm; }
        table.items th { text-align: left; border-bottom: 1px solid #999; padding: 2mm; font-size: 10pt; }
        table.items td { padding: 2mm; border-bottom: 1px solid #eee; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .totals { margin-left: auto; margin-top: 6mm; width: 45%; }
        .totals td { padding: 1.5mm 2mm; }
        .grand td { font-weight: 700; font-size: 13pt; border-top: 2px solid #111; }
        .footer { margin-top: 12mm; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ $primary === 'si' ? $shop['name_si'] : $shop['name_en'] }}</h1>
            <div class="muted">{{ $primary === 'si' ? ($shop['address_si'] ?: $shop['address_en']) : $shop['address_en'] }}</div>
            <div class="muted">{{ $shop['phone'] }}</div>
            <div class="title">{{ $t('quotation') }}</div>
        </div>
        <table class="meta">
            <tr><td class="muted">{{ $t('quotation_no') }}</td><td>{{ $quotation->number }}</td></tr>
            <tr><td class="muted">{{ $t('date') }}</td><td>{{ $quotation->created_at->format('Y-m-d') }}</td></tr>
            <tr><td class="muted">{{ $t('valid_until') }}</td><td>{{ $quotation->valid_until->format('Y-m-d') }}</td></tr>
            @if ($name = $quotation->customerLabel())<tr><td class="muted">{{ $t('customer') }}</td><td>{{ $name }}</td></tr>@endif
            <tr><td class="muted">{{ $t('staff') }}</td><td>{{ $quotation->creator->name }}</td></tr>
        </table>
    </div>

    <table class="items">
        <thead>
            <tr><th>{{ $t('item') }}</th><th class="num">{{ $t('qty') }}</th><th class="num">{{ $t('price') }}</th><th class="num">{{ $t('discount') }}</th><th class="num">{{ $t('amount') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($quotation->lines as $line)
                <tr>
                    <td>{{ $primary === 'si' ? ($line->name_si_snapshot ?: $line->name_snapshot) : $line->name_snapshot }}</td>
                    <td class="num">{{ $qty($line->qty) }} {{ $primary === 'si' ? ($line->unit_si_snapshot ?: $line->unit_snapshot) : $line->unit_snapshot }}</td>
                    <td class="num">{{ $money($line->unit_price) }}</td>
                    <td class="num">{{ (float) $line->discount_amount > 0 ? '-'.$money($line->discount_amount) : '' }}</td>
                    <td class="num">{{ $money($line->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>{{ $t('subtotal') }}</td><td class="num">{{ $money($quotation->subtotal) }}</td></tr>
        @if ($discount > 0)<tr><td>{{ $t('discount') }}</td><td class="num">-{{ $money($discount) }}</td></tr>@endif
        <tr class="grand"><td>{{ $t('total') }}</td><td class="num">{{ $money($quotation->total) }}</td></tr>
    </table>

    <div class="footer muted">{{ $t('quotation_note') }}@if ($quotation->note)<br>{{ $quotation->note }}@endif</div>
</body>
</html>
