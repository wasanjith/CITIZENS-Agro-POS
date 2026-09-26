{{--
    A4 PDF of any report (headless Chrome). Expects: $report, $input, $columns, $result, $shop.
--}}
@php
    $font = fn (int $weight) => base64_encode(file_get_contents(public_path("fonts/NotoSansSinhala-{$weight}.woff2")));
    $hasTotals = $result->rows !== [] && collect($columns)->contains('total', true);
    $landscape = $report->landscape($input);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title() }}</title>
    <style>
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 400; src: url(data:font/woff2;base64,{{ $font(400) }}) format('woff2'); }
        @font-face { font-family: 'Noto Sans Sinhala'; font-weight: 700; src: url(data:font/woff2;base64,{{ $font(700) }}) format('woff2'); }
        @page { size: A4 {{ $landscape ? 'landscape' : 'portrait' }}; margin: 12mm; }
        body { font-family: 'Noto Sans Sinhala', sans-serif; font-size: {{ count($columns) > 10 ? '7.5pt' : '9pt' }}; color: #111; }
        h1 { margin: 0; font-size: 15pt; }
        .muted { color: #555; }
        .header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #16824f; padding-bottom: 3mm; margin-bottom: 4mm; }
        .tiles { display: flex; flex-wrap: wrap; gap: 3mm; margin-bottom: 4mm; }
        .tile { border: 1px solid #ccc; border-radius: 2mm; padding: 1.5mm 3mm; }
        .tile b { display: block; font-size: 11pt; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { text-align: left; border-bottom: 1px solid #999; padding: 1.2mm; font-size: 0.9em; }
        td { padding: 1.2mm; border-bottom: 1px solid #eee; vertical-align: top; }
        tr { page-break-inside: avoid; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .alert td { color: #b91c1c; }
        .total td { font-weight: 700; border-top: 2px solid #111; }
        .notes { margin-top: 4mm; font-size: 0.85em; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <div class="muted">{{ $shop['name_en'] ?? '' }}</div>
            <h1>{{ $report->title() }}</h1>
            <div class="muted">{{ $report->subtitle($input) }}</div>
        </div>
        <div class="muted">Printed {{ now()->format('Y-m-d H:i') }} by {{ $input->user->name }}</div>
    </div>

    @if ($result->tiles)
        <div class="tiles">
            @foreach ($result->tiles as $label => $value)
                <div class="tile"><span class="muted">{{ $label }}</span><b>{{ $value }}</b></div>
            @endforeach
        </div>
    @endif

    @if ($result->rows === [])
        <p class="muted">Nothing matches these filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th @class(['num' => $column->isNumeric()])>{{ $column->label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($result->rows as $row)
                    <tr @class(['alert' => ! empty($row['_alert'])])>
                        @foreach ($columns as $column)
                            <td @class(['num' => $column->isNumeric()])>{{ $column->format($row[$column->key] ?? null) }}</td>
                        @endforeach
                    </tr>
                @endforeach
                @if ($hasTotals)
                    <tr class="total">
                        @foreach ($columns as $column)
                            <td @class(['num' => $column->isNumeric()])>{{ $column->total ? $column->format($column->sum($result->rows)) : ($loop->first ? 'Total' : '') }}</td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
    @endif

    @if ($result->notes)
        <div class="notes muted">
            @foreach ($result->notes as $note)
                <div>{{ $note }}</div>
            @endforeach
        </div>
    @endif
</body>
</html>
