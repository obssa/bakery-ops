{{--
    The PDF of a weekly summary (step 4.4d), printed by App\Modules\Analytics\Weekly\WeeklySummaryPdf through Shared's PdfRenderer (dompdf,
    A4): $s is WeeklySummary::build, $l the Labels of the PDF's language, $name(row) a name in it, $change(pct) a signed percent or '-'.
    $am puts the text in Noto Sans Ethiopic first (Latin text and the digits in Helvetica, a PDF core font).
--}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 28pt 32pt; }
    body { font-family: {!! $am ? '"Noto Sans Ethiopic", Helvetica' : 'Helvetica, "Noto Sans Ethiopic"' !!}, "DejaVu Sans"; font-size: 9pt; color: #111; line-height: 1.3; }
    h1 { font-size: 16pt; margin: 0 0 2pt 0; }
    h2 { font-size: 10.5pt; margin: 10pt 0 3pt 0; }
    .muted { color: #555; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-weight: bold; border-bottom: 0.8pt solid #888; padding: 2pt 3pt; }
    td { padding: 1.6pt 3pt; border-bottom: 0.3pt solid #ddd; }
    .num { text-align: right; white-space: nowrap; }
    .footer { margin-top: 10pt; font-size: 7pt; color: #666; }
</style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div>{{ $range }}</div>
    <div class="muted">{{ $stamp }}@if ($stale !== null) {{ $stale }}@endif</div>

    <h2>{{ $l->text('weekly.headline') }}</h2>
    <table>
        <tr>
            <th></th>
            <th class="num">{{ $l->text('weekly.headline') }}</th>
            <th class="num">{{ $l->text('weekly.week_before') }}</th>
            <th class="num">{{ $l->text('weekly.change') }}</th>
        </tr>
        <tr>
            <td>{{ $l->text('columns.revenue') }}</td>
            <td class="num">{{ $s['revenue'] }}</td>
            <td class="num">{{ $s['revenue_before'] }}</td>
            <td class="num">{{ $change($s['revenue_change_pct']) }}</td>
        </tr>
        <tr>
            <td>{{ $l->text('columns.orders_count') }}</td>
            <td class="num">{{ $s['orders_count'] }}</td>
            <td class="num">{{ $s['orders_before'] }}</td>
            <td class="num">{{ $change($s['orders_change_pct']) }}</td>
        </tr>
        <tr>
            <td>{{ $l->text('weekly.net_revenue') }}</td>
            <td class="num">{{ $s['net_revenue'] }}</td>
            <td></td>
            <td></td>
        </tr>
        <tr>
            <td>{{ $l->text('columns.avg_ticket') }}</td>
            <td class="num">{{ $s['avg_ticket'] }}</td>
            <td></td>
            <td></td>
        </tr>
    </table>

    @foreach (['best' => $s['best_items'], 'worst' => $s['worst_items']] as $which => $items)
        <h2>{{ $l->text('weekly.'.$which) }}</h2>
        @if ($items === [])
            <div class="muted">{{ $l->text('weekly.none') }}</div>
        @else
            <table>
                <tr>
                    <th>{{ $l->text('columns.item') }}</th>
                    <th class="num">{{ $l->text('columns.units_sold_qty') }}</th>
                    <th class="num">{{ $l->text('columns.revenue') }}</th>
                    <th class="num">{{ $l->text('columns.margin_total') }}</th>
                    <th class="num">{{ $l->text('columns.margin_pct') }}</th>
                </tr>
                @foreach ($items as $item)
                    <tr>
                        <td>{{ $name($item) }}@if (! $item['cogs_complete']) <span class="muted">({{ $l->text('weekly.cost_incomplete') }})</span>@endif</td>
                        <td class="num">{{ $item['units_sold_qty'] }}</td>
                        <td class="num">{{ $item['revenue'] }}</td>
                        <td class="num">{{ $item['margin_total'] }}</td>
                        <td class="num">{{ $item['margin_pct'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    @endforeach

    <h2>{{ $l->text('weekly.channels') }}</h2>
    @if ($s['channels'] === [])
        <div class="muted">{{ $l->text('weekly.none') }}</div>
    @else
        <table>
            <tr>
                <th>{{ $l->text('columns.channel') }}</th>
                <th class="num">{{ $l->text('columns.amount') }}</th>
                <th class="num">{{ $l->text('columns.share') }}</th>
            </tr>
            @foreach ($s['channels'] as $channel)
                <tr>
                    <td>{{ $name($channel) }}</td>
                    <td class="num">{{ $channel['amount'] }}</td>
                    <td class="num">{{ $channel['share'] ?? '-' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>{{ $l->text('weekly.refunds_voids') }}</h2>
    <table>
        <tr><td>{{ $l->text('columns.refund_count') }}</td><td class="num">{{ $s['refund_count'] }}</td><td>{{ $l->text('columns.refund_total') }}</td><td class="num">{{ $s['refund_total'] }}</td></tr>
        <tr><td>{{ $l->text('weekly.voided') }}</td><td class="num">{{ $s['voided_count'] }}</td><td>{{ $l->text('columns.amount') }}</td><td class="num">{{ $s['voided_total'] }}</td></tr>
        <tr><td>{{ $l->text('weekly.cancelled_count') }}</td><td class="num">{{ $s['cancelled_count'] }}</td><td></td><td></td></tr>
    </table>

    <h2>{{ $l->text('weekly.variances') }}</h2>
    <table>
        <tr><td>{{ $l->text('columns.variance_line_count') }}</td><td class="num">{{ $s['variance_line_count'] }}</td><td>{{ $l->text('columns.cash_variance_total') }}</td><td class="num">{{ $s['cash_variance_total'] }}</td></tr>
    </table>

    <h2>{{ $l->text('weekly.stockouts') }}: {{ $s['stockout_count'] }}</h2>
    @if ($s['stockout_items'] === [])
        <div class="muted">{{ $l->text('weekly.none') }}</div>
    @else
        <table>
            @foreach ($s['stockout_items'] as $item)
                <tr><td>{{ $name($item) }}</td><td class="num">{{ $item['stockout_count'] }}</td></tr>
            @endforeach
        </table>
    @endif

    <div class="footer">{{ $printed }}</div>
</body>
</html>
