{{--
    The PDF summary of an analytics export (step 4.4d), printed by App\Modules\Analytics\Export\ReportFiles through Shared's PdfRenderer
    (dompdf, landscape A4, at most three pages): the title, the range, the freshness stamp, the totals and the report's table. Every text
    arrives in the file's language; $am puts it in Noto Sans Ethiopic first (Latin text and the digits in Helvetica, a PDF core font).
    $rows are strings already ('-' for a figure that is not there); $more says how many rows the spreadsheet has beyond the table.
--}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 24pt 28pt; }
    body { font-family: {!! $am ? '"Noto Sans Ethiopic", Helvetica' : 'Helvetica, "Noto Sans Ethiopic"' !!}, "DejaVu Sans"; font-size: 8.5pt; color: #111; line-height: 1.25; }
    h1 { font-size: 15pt; margin: 0 0 2pt 0; }
    .muted { color: #555; }
    .stamp { margin: 4pt 0 8pt 0; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-weight: bold; border-bottom: 0.8pt solid #888; padding: 2pt 3pt; vertical-align: bottom; }
    td { padding: 1.4pt 3pt; border-bottom: 0.3pt solid #ddd; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .totals { width: auto; margin-bottom: 10pt; }
    .totals td { border-bottom: none; padding: 1pt 12pt 1pt 0; }
    .totals .value { font-weight: bold; }
    .more { margin-top: 6pt; font-style: italic; }
    .footer { margin-top: 8pt; font-size: 7pt; color: #666; }
</style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div>{{ $range }}</div>
    <div class="stamp muted">{{ $stamp }}@if ($stale !== null) {{ $stale }}@endif</div>

    @if ($totals !== [])
        <table class="totals">
            <tr>
                <td colspan="{{ count($totals) * 2 }}"><strong>{{ $totalsTitle }}</strong></td>
            </tr>
            <tr>
                @foreach ($totals as [$label, $value])
                    <td>{{ $label }}</td><td class="value num">{{ $value }}</td>
                @endforeach
            </tr>
        </table>
    @endif

    @if ($rows === [])
        <p class="muted">{{ $empty }}</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th class="{{ $column['number'] ? 'num' : '' }}">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $i => $value)
                            <td class="{{ $columns[$i]['number'] ? 'num' : '' }}">{{ $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($more !== null)
        <div class="more">{{ $more }}</div>
    @endif
    <div class="footer">{{ $printed }}</div>
</body>
</html>
