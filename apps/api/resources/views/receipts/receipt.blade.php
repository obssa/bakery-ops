{{--
    A receipt (step 4.3e), printed by App\Modules\Payments\Support\Receipts\ReceiptRenderer with dompdf. $r is the Receipt; $l(key) a label of
    lang/{en,am}/receipts.php in the receipt's language(s); $n(en, am) a name in them; $qty(q) a quantity without trailing zeros; $lines the
    lines printed and $more the ones folded into one row ({count, total}) when the receipt would not fit its pages. Amharic text is in
    span.am (Noto Sans Ethiopic), the rest in Helvetica (a PDF core font: nothing embedded); dompdf falls back per character along the
    font-family list, to DejaVu Sans for a letter neither has. No person is named.
--}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 26pt 30pt; }
    body { font-family: Helvetica, "Noto Sans Ethiopic", "DejaVu Sans"; font-size: {{ $compact ? '7.5' : '9' }}pt; color: #111; line-height: 1.2; }
    .am { font-family: "Noto Sans Ethiopic", Helvetica, "DejaVu Sans"; }
    .muted { color: #555; }
    .small { font-size: 0.88em; }
    h1 { font-size: 1.45em; margin: 0 0 2pt 0; }
    h2 { font-size: 1.1em; margin: {{ $compact ? '4' : '8' }}pt 0 2pt 0; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-weight: bold; border-bottom: 0.8pt solid #888; padding: 2pt; }
    td { padding: {{ $compact ? '0.6' : '1.6' }}pt 2pt; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .mod td { color: #444; padding-top: 0; }
    .mod .name { padding-left: 10pt; }
    .sum td { padding: 1pt 2pt; }
    .grand td { font-weight: bold; font-size: 1.15em; border-top: 0.8pt solid #333; padding-top: 3pt; }
    .proforma { border: 1.4pt solid #a00; color: #a00; padding: 4pt; margin: 4pt 0; text-align: center; font-weight: bold; }
    .head td { padding: 0 2pt; }
    .footer { margin-top: 8pt; text-align: center; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <h1>{!! $n($r->business['name_en'], $r->business['name_am']) !!}</h1>
                @if ($r->business['address_en'] !== null || $r->business['address_am'] !== null)
                    <div class="small">{!! $n((string) $r->business['address_en'], (string) $r->business['address_am']) !!}</div>
                @endif
                @if ($r->business['phone'] !== null)
                    <div class="small">{!! $l('phone') !!}: {{ $r->business['phone'] }}</div>
                @endif
                @if ($r->business['tin'] !== null)
                    <div class="small">{!! $l('tin') !!}: {{ $r->business['tin'] }}</div>
                @endif
            </td>
            <td class="num">
                <h1>{!! $l($r->proforma ? 'proforma' : 'title') !!}</h1>
                <div>{!! $l('order') !!} #{{ $r->orderNo ?? '-' }}</div>
                <div>{!! $l('date') !!}: {{ $r->issuedAt->format('Y-m-d H:i') }}</div>
                @if ($r->ethiopianDate !== null)
                    <div class="small">{!! $l('ethiopian_date') !!}: {!! $n($r->ethiopianDate->format('en'), $r->ethiopianDate->format('am')) !!}</div>
                @endif
                <div class="small">{!! $l('type.'.$r->orderType) !!}@if ($r->tableCode !== null) &middot; {!! $l('table') !!} {{ $r->tableCode }}@endif</div>
            </td>
        </tr>
    </table>

    @if ($r->proforma)
        <div class="proforma">{!! $l('proforma') !!} &middot; {!! $l('proforma_note') !!}</div>
    @endif

    <table>
        <thead>
            <tr><th>{!! $l('item') !!}</th><th class="num">{!! $l('qty') !!}</th><th class="num">{!! $l('price') !!}</th><th class="num">{!! $l('amount') !!}</th></tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{!! $n($line['name_en'], $line['name_am']) !!}</td>
                    <td class="num">{{ $qty($line['qty']) }}</td>
                    <td class="num">{{ $line['unit_price'] }}</td>
                    <td class="num">{{ $line['line_total'] }}</td>
                </tr>
                @foreach ($line['modifiers'] as $modifier)
                    <tr class="mod">
                        <td class="name">+ {!! $n($modifier['name_en'], $modifier['name_am']) !!}</td>
                        <td></td>
                        <td class="num">{{ $modifier['price_delta'] === '0.00' ? '' : $modifier['price_delta'] }}</td>
                        <td></td>
                    </tr>
                @endforeach
            @endforeach
            @if ($more !== null)
                <tr><td>{!! $l('more_items', ['count' => $more['count']]) !!}</td><td></td><td></td><td class="num">{{ $more['total'] }}</td></tr>
            @endif
        </tbody>
    </table>

    <table class="sum">
        <tr><td style="width: 70%"></td><td>{!! $l('subtotal') !!}</td><td class="num">{{ $r->money['subtotal'] }}</td></tr>
        @if ($r->money['discount'] !== '0.00')
            <tr><td></td><td>{!! $l('discount') !!}</td><td class="num">-{{ $r->money['discount'] }}</td></tr>
        @endif
        @if ($r->money['tax'] !== '0.00')
            <tr><td></td><td>{!! $l('tax') !!}</td><td class="num">{{ $r->money['tax'] }}</td></tr>
        @endif
        <tr class="grand"><td></td><td>{!! $l('total') !!}</td><td class="num">{{ $r->money['total'] }} {{ $r->money['currency'] }}</td></tr>
        <tr><td></td><td>{!! $l('paid') !!}</td><td class="num">{{ $r->money['paid'] }}</td></tr>
        @if ($r->money['balance'] !== '0.00')
            <tr><td></td><td>{!! $l('balance') !!}</td><td class="num">{{ $r->money['balance'] }}</td></tr>
        @endif
        @if ($r->money['change'] !== '0.00')
            <tr><td></td><td>{!! $l('change') !!}</td><td class="num">{{ $r->money['change'] }}</td></tr>
        @endif
        @if ($r->refunds !== [])
            <tr><td></td><td>{!! $l('refunded') !!}</td><td class="num">-{{ $r->money['refunded'] }}</td></tr>
            <tr><td></td><td>{!! $l('net_paid') !!}</td><td class="num">{{ $r->money['net_paid'] }}</td></tr>
        @endif
    </table>

    @if ($r->payments !== [])
        <h2>{!! $l('payments') !!}</h2>
        <table>
            @foreach ($r->payments as $payment)
                <tr>
                    <td>{!! $n($payment['channel_en'], $payment['channel_am']) !!}@if ($payment['reference_tail'] !== null) <span class="muted small">&middot; {!! $l('reference') !!} {{ $payment['reference_tail'] }}</span>@endif
                        @if ($payment['tendered'] !== null && $payment['change'] !== null && $payment['change'] !== '0.00')
                            <span class="muted small">&middot; {!! $l('tendered') !!} {{ $payment['tendered'] }} &middot; {!! $l('change') !!} {{ $payment['change'] }}</span>
                        @endif
                    </td>
                    <td class="num">{{ $payment['amount'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($r->refunds !== [])
        <h2>{!! $l('refunds') !!}</h2>
        <table>
            @foreach ($r->refunds as $refund)
                <tr>
                    <td>{!! $n($refund['channel_en'], $refund['channel_am']) !!} <span class="muted small">&middot; {{ $refund['refunded_at'] }}</span></td>
                    <td class="num">-{{ $refund['amount'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($r->business['footer_en'] !== null || $r->business['footer_am'] !== null)
        <div class="footer">{!! $n((string) $r->business['footer_en'], (string) $r->business['footer_am']) !!}</div>
    @endif
</body>
</html>
