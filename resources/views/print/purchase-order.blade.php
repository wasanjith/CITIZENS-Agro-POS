{{--
    Purchase order for the supplier, A4 PDF rendered by headless Chrome (spatie/laravel-pdf).
    The Sinhala font is embedded so the shop's Sinhala name renders on any server.
--}}
@php
    use App\Domain\Inventory\Support\Qty;

    $font = fn (int $weight) => base64_encode(file_get_contents(public_path("fonts/NotoSansSinhala-{$weight}.woff2")));
    $money = fn (string $amount) => number_format((float) $amount, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $order->number }}</title>
    <style>
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 400; src: url(data:font/woff2;base64,{{ $font(400) }}) format('woff2'); }
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 700; src: url(data:font/woff2;base64,{{ $font(700) }}) format('woff2'); }
        @page { size: A4; margin: 18mm 16mm; }
        body { font-family: 'Noto Sans Sinhala', sans-serif; font-size: 10.5pt; color: #111; }
        h1 { margin: 0; font-size: 18pt; }
        h2 { margin: 0 0 2mm; font-size: 14pt; letter-spacing: 0.05em; }
        .muted { color: #555; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #16824f; padding-bottom: 6mm; }
        .parties { display: flex; justify-content: space-between; margin-top: 6mm; gap: 10mm; }
        .meta td { padding: 0.8mm 4mm 0.8mm 0; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8mm; }
        table.items th { text-align: left; border-bottom: 1px solid #999; padding: 2mm; font-size: 9.5pt; }
        table.items td { padding: 2mm; border-bottom: 1px solid #eee; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .code { font-family: monospace; font-size: 9pt; color: #555; }
        .totals { margin-left: auto; margin-top: 6mm; width: 45%; border-collapse: collapse; }
        .totals td { padding: 1.5mm 2mm; }
        .grand td { font-weight: 700; font-size: 12pt; border-top: 2px solid #111; }
        .note { margin-top: 8mm; white-space: pre-line; }
        .sign { margin-top: 20mm; display: flex; justify-content: space-between; }
        .sign div { border-top: 1px solid #999; width: 60mm; padding-top: 1mm; text-align: center; font-size: 9pt; color: #555; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ $shop['name_en'] }}</h1>
            <div>{{ $shop['name_si'] }}</div>
            <div class="muted">{{ $shop['address_en'] }}</div>
            <div class="muted">{{ $shop['phone'] }}</div>
        </div>
        <div style="text-align: right">
            <h2>PURCHASE ORDER</h2>
            <table class="meta" style="margin-left: auto">
                <tr><td class="muted">Number</td><td><strong>{{ $order->number }}</strong></td></tr>
                <tr><td class="muted">Date</td><td>{{ $order->order_date->format('Y-m-d') }}</td></tr>
                @if ($order->expected_date)
                    <tr><td class="muted">Deliver by</td><td>{{ $order->expected_date->format('Y-m-d') }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    <div class="parties">
        <div>
            <div class="muted">Supplier</div>
            <strong>{{ $order->supplier->name }}</strong>
            @if ($order->supplier->contact_person)<div>Attn: {{ $order->supplier->contact_person }}</div>@endif
            @if ($order->supplier->address)<div>{{ $order->supplier->address }}</div>@endif
            @if ($order->supplier->phone)<div>{{ $order->supplier->phone }}</div>@endif
        </div>
        <div style="text-align: right">
            <div class="muted">Payment terms</div>
            <div>{{ $order->supplier->payment_terms_days ? $order->supplier->payment_terms_days.' days' : 'Cash on delivery' }}</div>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th class="num">Quantity</th>
                <th class="num">Unit price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->lines as $line)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        {{ $line->product->name }} {{ $line->variant?->name }}
                        <div class="code">{{ $line->variant?->short_code ?? $line->product->short_code }}</div>
                    </td>
                    <td class="num">{{ Qty::format($line->qty) }} {{ $line->unit->name }}</td>
                    <td class="num">{{ $line->unit_cost !== null ? $money($line->unit_cost) : '' }}</td>
                    <td class="num">{{ $money($line->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="muted">Subtotal</td><td class="num">{{ $money($order->subtotal) }}</td></tr>
        @if ((float) $order->discount)
            <tr><td class="muted">Discount</td><td class="num">− {{ $money($order->discount) }}</td></tr>
        @endif
        @if ((float) $order->tax)
            <tr><td class="muted">Tax</td><td class="num">{{ $money($order->tax) }}</td></tr>
        @endif
        <tr class="grand"><td>Total (Rs.)</td><td class="num">{{ $money($order->total) }}</td></tr>
    </table>

    @if ($order->note)
        <div class="note"><strong>Note:</strong> {{ $order->note }}</div>
    @endif

    <div class="sign">
        <div>Approved by{{ $order->approver ? ': '.$order->approver->name : '' }}</div>
        <div>Supplier signature</div>
    </div>
</body>
</html>
