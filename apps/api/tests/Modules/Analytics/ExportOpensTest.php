<?php

declare(strict_types=1);

use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use Tests\Support\Dashboards;
use Tests\Support\Exports;
use Tests\Support\SummaryRows;

pest()->group('module:analytics', 'feature');

/*
 * GET /api/v1/analytics/exports/{report} (phase-4 plan section 4.6, step 4.4d; analytics.view: admin, manager), report one of sales,
 * channels, items, stations, staff and shifts; ?format=xlsx|pdf|csv (xlsx by default), ?locale=en|am (English by default) and the common
 * range of the dashboards (RangeRequest: the last 30 days by default, at most 400, never after today). Read from the summary tables only
 * (DashboardsReadSummariesOnlyTest covers the route), with the figures the dashboards show:
 *   - xlsx: OpenSpout's streaming writer; one sheet per table of the report (the dashboard's own, then its rows day by day), then About
 *     (the report, the range, the zone, as_of and the days not built). Sheet names and the header row in the asked language. A figure is a
 *     NUMBER whose stored value is its decimal string exactly (<v>60.00</v>), written without a PHP float (Shared\Exports\XlsxFile); a
 *     count is an integer; a figure of a day that is not built is an empty cell, never a zero (R-U); every text stays text.
 *   - csv: the report's first table only, UTF-8 with a byte-order mark, a typed text that a spreadsheet would run as a formula prefixed
 *     with an apostrophe.
 * One audit row analytics.export per export (report, format, locale, range, rows). The files are read back with OpenSpout.
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $this->cash = $d->channel('cash');
    $this->telebirr = $d->channel('telebirr');
    $this->croissant = $d->product('Croissant', 'ክሮሳንት', ['category_id' => $d->category('Pastry', 'ኬክ')->id]);
    $this->flour = $d->ingredient('Flour', 'ዱቄት');
    $d->rows->built('2026-11-16')
        ->sales('2026-11-16', ['orders_count' => 2, 'items_qty' => '3.0000', 'revenue' => '180.00', 'refund_total' => '0.00', 'net_revenue' => '180.00', 'avg_ticket' => '90.00'])
        ->hour('2026-11-16', 9, ['orders_count' => 2, 'items_qty' => '3.0000', 'revenue' => '180.00'])
        ->channel('2026-11-16', $this->cash, ['receipts_count' => 1, 'amount' => '60.00', 'net_amount' => '60.00', 'without_reference_count' => 1, 'without_photo_count' => 1])
        ->channel('2026-11-16', $this->telebirr, ['receipts_count' => 1, 'amount' => '120.00', 'net_amount' => '120.00', 'verified_count' => 1])
        ->item('2026-11-16', $this->croissant->id, ['category_id' => $this->croissant->category_id, 'units_sold_qty' => '3.0000', 'revenue' => '180.00',
            'cogs_total' => '45.50', 'margin_total' => '134.50', 'cogs_complete' => true, 'produced_qty' => '10.0000', 'waste_qty' => '1.5000'])
        ->item('2026-11-16', $this->flour->id, ['kind' => 'ingredient', 'consumed_qty' => '2.2500', 'purchased_qty' => '25.0000', 'stockout_count' => 1])
        ->station('2026-11-16', $d->kitchen->id, ['tickets_count' => 2, 'lines_served' => 3, 'prep_samples' => 2, 'prep_seconds_sum' => 610, 'avg_prep_seconds' => 305,
            'p90_prep_seconds' => 400, 'shifts_count' => 1, 'shifts_locked_count' => 1, 'variance_line_count' => 2, 'cash_variance_total' => '-5.00'])
        ->user('2026-11-16', $d->users['waiter']->id, 'waiter', ['orders_count' => 2, 'items_qty' => '3.0000', 'revenue' => '180.00'])
        ->user('2026-11-16', $d->users['cashier']->id, 'cashier', ['payments_count' => 2, 'payments_amount' => '180.00']);
    $d->rows->built('2026-11-17')
        ->sales('2026-11-17', ['orders_count' => 1, 'items_qty' => '1.0000', 'revenue' => '60.00', 'net_revenue' => '60.00', 'avg_ticket' => '60.00'])
        ->channel('2026-11-17', $this->cash, ['receipts_count' => 1, 'amount' => '60.00', 'net_amount' => '60.00'])
        ->item('2026-11-17', $this->croissant->id, ['category_id' => $this->croissant->category_id, 'units_sold_qty' => '1.0000', 'revenue' => '60.00', 'cogs_complete' => false]);
    // 2026-11-18 is not built
    $this->range = ['from' => '2026-11-16', 'to' => '2026-11-18'];
    $this->export = fn (string $report, array $query = [], string $as = 'admin') => $this->d->get('exports/'.$report, $query + $this->range, $as);
    $this->label = fn (string $key, string $locale = 'en'): string => (string) trans('reports.columns.'.$key, [], $locale);
    $this->sheet = fn (string $key, string $locale = 'en'): string => (string) trans('reports.sheets.'.$key, [], $locale);
});

it('opens as a spreadsheet with a sheet per table of each report, the header rows in English, a row per figure', function (string $report, array $sheets) {
    $response = ($this->export)($report)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toBe("attachment; filename=analytics-{$report}-2026-11-16-2026-11-18.xlsx")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $read = Exports::values($response);
    $expected = [];
    foreach ($sheets as $key => [$columns, $rows]) {
        $expected[($this->sheet)($key)] = [$columns, $rows];
    }
    expect(array_keys($read))->toBe([...array_keys($expected), ($this->sheet)('about')]);
    foreach ($expected as $name => [$columns, $rows]) {
        expect($read[$name][0])->toBe(array_map(fn (string $c): string => ($this->label)($c), $columns), "the header row of {$name}")
            ->and(count($read[$name]) - 1)->toBe($rows, "the rows of {$name}");
    }
})->with([
    'sales' => ['sales', [
        'sales' => [['day', 'built', 'orders_count', 'items_qty', 'revenue', 'refund_total', 'net_revenue', 'avg_ticket'], 3],
        'hours' => [['hour', 'orders_count', 'items_qty', 'revenue'], 24],
    ]],
    'channels' => ['channels', [
        'channels' => [['channel_code', 'channel', 'receipts_count', 'amount', 'refund_count', 'refund_amount', 'net_amount', 'share', 'without_reference_count',
            'without_photo_count', 'verified_count', 'disputed_count', 'duplicate_reference_count'], 2],
        'channel_days' => [['day', 'channel_code', 'channel', 'receipts_count', 'amount', 'refund_count', 'refund_amount', 'net_amount', 'without_reference_count',
            'without_photo_count', 'verified_count', 'disputed_count', 'duplicate_reference_count'], 3],
    ]],
    'items' => ['items', [
        'items' => [['item', 'kind', 'category', 'units_sold_qty', 'refunded_qty', 'revenue', 'cogs_total', 'margin_total', 'margin_pct', 'cogs_complete',
            'cogs_incomplete_days', 'produced_qty', 'consumed_qty', 'waste_qty', 'purchased_qty', 'stockout_count'], 2],
        'item_days' => [['day', 'item', 'kind', 'units_sold_qty', 'refunded_qty', 'revenue', 'cogs_total', 'margin_total', 'margin_pct', 'cogs_complete',
            'produced_qty', 'consumed_qty', 'waste_qty', 'purchased_qty', 'adjusted_qty', 'stockout_count', 'closing_on_hand_qty', 'closing_value_total'], 3],
    ]],
    'stations' => ['stations', [
        'stations' => [['station', 'tickets_count', 'lines_served', 'lines_rejected', 'prep_samples', 'avg_prep_seconds', 'p90_prep_seconds',
            'worst_day_p90_prep_seconds', 'batches_finished', 'produced_qty', 'waste_qty', 'shifts_count', 'shifts_locked_count', 'variance_line_count',
            'cash_variance_total', 'stockout_count'], 1],
        'station_days' => [['day', 'station', 'tickets_count', 'lines_served', 'lines_rejected', 'prep_samples', 'avg_prep_seconds', 'p90_prep_seconds',
            'batches_finished', 'produced_qty', 'waste_qty', 'shifts_count', 'shifts_locked_count', 'variance_line_count', 'cash_variance_total', 'stockout_count'], 1],
    ]],
    'staff' => ['staff', [
        'staff' => [['person', 'role_kind', 'orders_count', 'items_qty', 'revenue', 'payments_count', 'payments_amount', 'voids_count', 'refunds_count',
            'lines_prepared', 'prep_samples', 'avg_prep_seconds'], 2],
        'staff_days' => [['day', 'person', 'role_kind', 'orders_count', 'items_qty', 'revenue', 'payments_count', 'payments_amount', 'voids_count',
            'refunds_count', 'lines_prepared', 'prep_samples', 'avg_prep_seconds'], 2],
    ]],
    'shifts' => ['shifts', [
        'shifts' => [['day', 'built', 'shifts_count', 'shifts_locked_count', 'variance_line_count', 'cash_variance_total'], 3],
        'shift_stations' => [['day', 'station', 'shifts_count', 'shifts_locked_count', 'variance_line_count', 'cash_variance_total'], 1],
    ]],
]);

it('writes the figures as numbers holding their decimal strings exactly, and a day that is not built as empty cells', function () {
    $response = ($this->export)('sales')->assertOk();

    $sheets = Exports::sheets($response);
    $rows = $sheets[($this->sheet)('sales')];
    expect(array_map(fn ($cell) => $cell::class, $rows[1]))->toBe([
        StringCell::class, StringCell::class, NumericCell::class, NumericCell::class, NumericCell::class, NumericCell::class, NumericCell::class, NumericCell::class,
    ])
        ->and($rows[1][0]->getValue())->toBe('2026-11-16')
        ->and($rows[1][1]->getValue())->toBe('Yes')
        ->and($rows[1][2]->getValue())->toBe(2)
        ->and($rows[3][0]->getValue())->toBe('2026-11-18')
        ->and($rows[3][1]->getValue())->toBe('No')
        ->and(array_map(fn ($cell) => $cell::class, array_slice($rows[3], 2)))->toBe(array_fill(0, 6, EmptyCell::class), 'not built is not zero (R-U)');

    $xml = Exports::sheetXml($response, 1);
    expect($xml)->toContain('<v>180.00</v>')->toContain('<v>3.0000</v>')->toContain('<v>90.00</v>')->toContain('<v>60.00</v>')
        ->and($xml)->not->toContain("\u{E000}");
});

it('writes the figures of the other reports as the dashboards show them', function () {
    $channels = Exports::values(($this->export)('channels')->assertOk());
    expect($channels[($this->sheet)('channels')][1])->toBe(['cash', 'Cash', '2', '120', '0', '0', '120', '50', '1', '1', '0', '0', '0'])
        ->and($channels[($this->sheet)('channels')][2])->toBe(['telebirr', 'Telebirr', '1', '120', '0', '0', '120', '50', '0', '0', '1', '0', '0']);
    $channelXml = Exports::sheetXml(($this->export)('channels'), 1);
    expect($channelXml)->toContain('<v>120.00</v>')->toContain('<v>50.00</v>');

    $items = Exports::values(($this->export)('items')->assertOk());
    expect($items[($this->sheet)('items')][1])->toBe(['Croissant', 'Product', 'Pastry', '4', '0', '240', '45.5', '134.5', '56.04', 'No', '1', '10', '0', '1.5', '0', '0'])
        ->and($items[($this->sheet)('items')][2])->toBe(['Flour', 'Ingredient', null, '0', '0', '0', '0', '0', null, 'Yes', '0', '0', '2.25', '0', '25', '1'])
        ->and(array_column(array_slice($items[($this->sheet)('item_days')], 1), 0))->toBe(['2026-11-16', '2026-11-16', '2026-11-17']);

    $stations = Exports::values(($this->export)('stations')->assertOk());
    expect(array_slice($stations[($this->sheet)('stations')][1], 0, 8))->toBe(['Kitchen', '2', '3', '0', '2', '305', null, '400'], 'p90 only for one day, worst day beside it');
    $oneDay = Exports::values($this->d->get('exports/stations', ['from' => '2026-11-16', 'to' => '2026-11-16']));
    expect(array_slice($oneDay[($this->sheet)('stations')][1], 0, 8))->toBe(['Kitchen', '2', '3', '0', '2', '305', '400', '400']);

    $staff = Exports::values(($this->export)('staff')->assertOk());
    expect(array_slice(array_column(array_slice($staff[($this->sheet)('staff')], 1), 0), 0))->toBe(['Abebe', 'Selam'], 'waiters first, then cashiers')
        ->and($staff[($this->sheet)('staff')][1][1])->toBe('Waiter');

    $shifts = Exports::values(($this->export)('shifts')->assertOk());
    expect($shifts[($this->sheet)('shifts')][1])->toBe(['2026-11-16', 'Yes', '1', '1', '2', '-5'])
        ->and($shifts[($this->sheet)('shifts')][2])->toBe(['2026-11-17', 'Yes', '0', '0', '0', '0'])
        ->and($shifts[($this->sheet)('shifts')][3])->toBe(['2026-11-18', 'No', null, null, null, null]);
});

it('says when the figures were built and which days are not, on the About sheet', function () {
    $about = Exports::values(($this->export)('sales')->assertOk())[($this->sheet)('about')];

    $fields = array_column(array_slice($about, 1), 1, 0);
    expect($about[0])->toBe([(string) trans('reports.about.field'), (string) trans('reports.about.value')])
        ->and($fields[(string) trans('reports.about.report')])->toBe((string) trans('reports.titles.sales'))
        ->and($fields[(string) trans('reports.about.from')])->toBe('2026-11-16')
        ->and($fields[(string) trans('reports.about.to')])->toBe('2026-11-18')
        ->and($fields[(string) trans('reports.about.timezone')])->toBe('Africa/Addis_Ababa')
        ->and($fields[(string) trans('reports.about.as_of')])->toBe('2026-11-22 08:00', 'the oldest rebuild of the range, local time')
        ->and($fields[(string) trans('reports.about.not_built')])->toBe('2026-11-18')
        ->and($fields[(string) trans('reports.about.generated_at')])->toBe('2026-11-22 10:00');
});

it('writes the sheet names, the header rows, the yes and no and the names in Amharic when asked', function () {
    $read = Exports::values(($this->export)('items', ['locale' => 'am'])->assertOk());

    $items = $read[($this->sheet)('items', 'am')] ?? null;
    expect(array_keys($read))->toBe([($this->sheet)('items', 'am'), ($this->sheet)('item_days', 'am'), ($this->sheet)('about', 'am')])
        ->and(($this->sheet)('items', 'am'))->toMatch('/\p{Ethiopic}/u')
        ->and($items[0][0])->toBe(($this->label)('item', 'am'))->toMatch('/\p{Ethiopic}/u')
        ->and($items[0][3])->toBe(($this->label)('units_sold_qty', 'am'))
        ->and(array_slice($items[1], 0, 3))->toBe(['ክሮሳንት', (string) trans('reports.values.kind.product', [], 'am'), 'ኬክ'])
        ->and($items[1][9])->toBe((string) trans('reports.values.no', [], 'am'));
});

it('writes the first table as CSV with a byte-order mark, the figures as plain decimal strings, a formula-like name kept from running', function () {
    PaymentChannel::query()->whereKey($this->telebirr)->update(['name_en' => '=HYPERLINK("http://example.test","pay")']);

    $response = ($this->export)('channels', ['format' => 'csv'])->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=analytics-channels-2026-11-16-2026-11-18.csv')
        ->and(substr(Exports::body($response), 0, 3))->toBe("\xEF\xBB\xBF");
    $rows = Exports::csv($response);
    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe(array_map(fn (string $c): string => ($this->label)($c), ['channel_code', 'channel', 'receipts_count', 'amount', 'refund_count', 'refund_amount',
            'net_amount', 'share', 'without_reference_count', 'without_photo_count', 'verified_count', 'disputed_count', 'duplicate_reference_count']))
        ->and($rows[1])->toBe(['cash', 'Cash', '2', '120.00', '0', '0.00', '120.00', '50.00', '1', '1', '0', '0', '0'])
        ->and($rows[2][1])->toBe("'=HYPERLINK(\"http://example.test\",\"pay\")");
});

it('keeps a formula-like name as text in the spreadsheet, never a formula', function () {
    PaymentChannel::query()->whereKey($this->telebirr)->update(['name_en' => '=1+1']);

    $response = ($this->export)('channels')->assertOk();

    expect(Exports::sheetXml($response, 1))->not->toContain('<f>')
        ->and(Exports::values($response)[($this->sheet)('channels')][2][1])->toBe('=1+1');
});

it('audits each export once, with the report, the format, the language, the range and the rows', function () {
    ($this->export)('items', ['format' => 'csv', 'locale' => 'am'])->assertOk()->streamedContent();

    $audit = DB::table('audit_log')->where('action', 'analytics.export')->sole();
    expect($audit->actor_id)->toBe($this->d->users['admin']->id)
        ->and(json_decode((string) $audit->after, true))->toBe(['report' => 'items', 'format' => 'csv', 'locale' => 'am', 'from' => '2026-11-16', 'to' => '2026-11-18', 'rows' => 2]);
});

it('reads the last 30 days by default and refuses a range the dashboards refuse', function () {
    $response = $this->d->get('exports/shifts', [])->assertOk();
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=analytics-shifts-2026-10-24-2026-11-22.xlsx')
        ->and(Exports::values($response)[($this->sheet)('shifts')])->toHaveCount(31);

    foreach (Dashboards::rangeErrors() as [$query, $key]) {
        if (isset($query['compare'])) {
            continue;
        }
        $this->d->get('exports/sales', $query)->assertStatus(422)->assertJsonPath('message_key', $key);
    }
    $this->d->get('exports/sales', ['format' => 'docx'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->d->get('exports/sales', ['locale' => 'fr'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->d->get('exports/orders')->assertNotFound()->assertJsonPath('message_key', 'http.not_found');
    expect(DB::table('audit_log')->where('action', 'analytics.export')->count())->toBe(1, 'a refused export is not audited');
});

it('is for analytics.view: the admin and the manager', function (string $role, bool $allowed) {
    $response = ($this->export)('sales', ['format' => 'csv'], $role);

    $allowed ? $response->assertOk() : $response->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
})->with(Dashboards::roles(['admin', 'manager']));

it('keeps another branch\'s figures out', function () {
    $other = Branch::factory()->create();
    (new SummaryRows($other->id))->built('2026-11-16')->sales('2026-11-16', ['orders_count' => 9, 'revenue' => '999.00']);

    $rows = Exports::values($this->d->get('exports/sales', $this->range))[($this->sheet)('sales')];
    expect($rows[1][4])->toBe('180');
});
