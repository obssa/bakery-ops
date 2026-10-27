<?php

declare(strict_types=1);

use Tests\Support\Dashboards;
use Tests\Support\Exports;

pest()->group('module:analytics', 'feature');

/*
 * GET /api/v1/analytics/exports/{report}?format=pdf (phase-4 plan section 4.6, step 4.4d): a summary of one to three A4 pages with the
 * figures the spreadsheet holds and the freshness stamp, printed through Shared\Pdf (dompdf; Latin text and the digits in Helvetica,
 * Amharic in the bundled Noto Sans Ethiopic). It carries the report's title, the range and the zone, when the figures were built (as_of,
 * local time) and how many days of the range are not built, the report's totals, and its first table: a long range of sales or shifts is
 * printed by week (up to 92 days) or by month (beyond), and a table that would still run past three pages is cut, saying how many rows
 * the spreadsheet has beyond it. Read back with an independent parser.
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $this->croissant = $d->product('Croissant', 'ክሮሳንት');
    $d->rows->built('2026-11-16')
        ->sales('2026-11-16', ['orders_count' => 2, 'items_qty' => '3.0000', 'revenue' => '180.00', 'net_revenue' => '180.00', 'avg_ticket' => '90.00'])
        ->item('2026-11-16', $this->croissant->id, ['units_sold_qty' => '3.0000', 'revenue' => '180.00', 'cogs_total' => '45.50', 'margin_total' => '134.50']);
    $d->rows->built('2026-11-17')
        ->sales('2026-11-17', ['orders_count' => 1, 'items_qty' => '1.0000', 'revenue' => '60.25', 'net_revenue' => '60.25', 'avg_ticket' => '60.25']);
    $this->range = ['from' => '2026-11-16', 'to' => '2026-11-18'];
    $this->pdf = function (string $report, array $query = []) {
        $response = $this->d->get('exports/'.$report, $query + ['format' => 'pdf'])->assertOk();
        expect($response->headers->get('Content-Type'))->toBe('application/pdf');

        return Exports::body($response);
    };
});

it('prints the sales summary on one page with the title, the range, the stamp, the totals and the days', function () {
    $pdf = ($this->pdf)('sales', $this->range);

    $text = Exports::pdfText($pdf);
    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(Exports::pdfPages($pdf))->toBe(1)
        ->and($text)->toContain((string) trans('reports.titles.sales'))
        ->and($text)->toContain('2026-11-16')->toContain('2026-11-18')->toContain('Africa/Addis_Ababa')
        ->and($text)->toContain('2026-11-22 08:00')
        ->and($text)->toContain((string) trans_choice('reports.pdf.not_built', 1, ['count' => 1]))
        ->and($text)->toContain('240.25')
        ->and($text)->toContain('180.00')->toContain('60.25')->toContain('80.08');
});

it('answers it as a download named after the report and the range, never cached', function () {
    $response = $this->d->get('exports/items', $this->range + ['format' => 'pdf'])->assertOk();

    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=analytics-items-2026-11-16-2026-11-18.pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Exports::pdfText(Exports::body($response)))->toContain('Croissant')->toContain('134.50')->toContain('74.72');
});

it('prints in Amharic when asked: the title, the labels and the names', function () {
    $text = Exports::pdfText(($this->pdf)('items', $this->range + ['locale' => 'am']));

    expect($text)->toContain((string) trans('reports.titles.items', [], 'am'))
        ->and($text)->toContain('ክሮሳንት')
        ->and($text)->toContain((string) trans('reports.columns.revenue', [], 'am'))
        ->and($text)->not->toContain('Croissant');
});

it('prints every report within three pages', function (string $report) {
    $pdf = ($this->pdf)($report, $this->range);

    expect(Exports::pdfPages($pdf))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3)
        ->and(Exports::pdfText($pdf))->toContain((string) trans('reports.titles.'.$report));
})->with(['sales', 'channels', 'items', 'stations', 'staff', 'shifts']);

it('prints a long range of sales by month and keeps it within three pages', function () {
    $pdf = ($this->pdf)('sales', ['from' => '2025-10-20', 'to' => '2026-11-22']);

    $text = Exports::pdfText($pdf);
    expect(Exports::pdfPages($pdf))->toBeLessThanOrEqual(3)
        ->and($text)->toContain('2026-11')->toContain('2025-10')
        ->and($text)->not->toContain('2026-11-17');
});

it('prints a range of up to 92 days by week', function () {
    $text = Exports::pdfText(($this->pdf)('shifts', ['from' => '2026-09-01', 'to' => '2026-11-22']));

    expect($text)->toContain('2026-11-16')->not->toContain('2026-11-17');
});

it('cuts a table that would run past three pages and says how many rows the spreadsheet has beyond it', function () {
    for ($i = 1; $i <= 260; $i++) {
        $item = $this->d->product(sprintf('Product %03d', $i), sprintf('ምርት %03d', $i));
        $this->d->rows->item('2026-11-17', $item->id, ['units_sold_qty' => '1.0000', 'revenue' => sprintf('%d.00', 1000 - $i)]);
    }

    $pdf = ($this->pdf)('items', $this->range);

    $text = Exports::pdfText($pdf);
    $more = '/'.str_replace(preg_quote(':count', '/'), '[0-9]+', preg_quote((string) trans('reports.pdf.more'), '/')).'/';
    expect(Exports::pdfPages($pdf))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(3)
        ->and($text)->toContain('Product 001')
        ->and($text)->not->toContain('Product 260')
        ->and($text)->toMatch($more);
});
