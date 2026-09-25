{{--
    A4 count sheet printed from the browser. Blind count: system quantities are not shown.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Count sheet {{ $stocktake->number }}</title>
    @vite('resources/css/app.css')
    <style>
        @page { size: A4; margin: 12mm; }
        @media print { .no-print { display: none; } }
        body { font-size: 10pt; }
        td, th { padding: 1.6mm 2mm; border-bottom: 1px solid #ddd; vertical-align: top; }
        th { text-align: left; border-bottom: 1px solid #333; }
        tr { break-inside: avoid; }
        .box { border: 1px solid #999; height: 7mm; width: 28mm; }
    </style>
</head>
<body class="bg-white p-6 text-gray-900">
    <div class="no-print mb-4">
        <button type="button" onclick="window.print()" class="rounded-md bg-brand-600 px-3 py-2 text-sm font-semibold text-white">Print</button>
    </div>

    <h1 class="text-xl font-bold">Count sheet · {{ $stocktake->number }}</h1>
    <p class="mb-4 text-sm text-gray-600">{{ $stocktake->scopeLabel() }} · started {{ $stocktake->created_at?->format('Y-m-d H:i') }} · counted by: ____________________</p>

    <table class="w-full border-collapse">
        <thead>
            <tr>
                <th>Code</th>
                <th>Product</th>
                <th>Batch / expiry</th>
                <th>Unit</th>
                <th>Counted</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td class="font-mono">{{ $line->variant?->short_code ?? $line->product->short_code }}</td>
                    <td>
                        {{ $line->product->name }} {{ $line->variant?->name }}
                        @if ($line->product->name_si)
                            <div class="font-sinhala text-xs text-gray-600">{{ $line->product->name_si }}</div>
                        @endif
                    </td>
                    <td>{{ $line->batch && ! $line->batch->isDefault() ? $line->batch->label() : '' }}</td>
                    <td>{{ $line->product->baseUnit?->symbol }}</td>
                    <td><div class="box"></div></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
