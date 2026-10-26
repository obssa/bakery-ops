<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Dashboards;

pest()->group('module:analytics', 'feature');

/*
 * An analytics export holds at most analytics.export_max_rows rows (20 000, phase-4 plan section 4.6, step 4.4d; the payments export's
 * limit), so a file stays within the shared host's memory and time. The rows are those the file would hold: every table of an xlsx, the
 * first table of a CSV or a PDF; the header rows and the About sheet are not counted. They are counted from the summaries before anything
 * is written: more is 422 analytics.export.too_large {max_rows, rows}, in English and Amharic, nothing is written and nothing is audited.
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $items = [$d->product('Croissant', 'ክሮሳንት'), $d->product('Bread', 'ዳቦ'), $d->product('Cake', 'ኬክ')];
    foreach (['2026-11-16', '2026-11-17'] as $day) {
        $d->rows->built($day);
        foreach ($items as $item) {
            $d->rows->item($day, $item->id, ['units_sold_qty' => '1.0000', 'revenue' => '10.00']);
        }
    }
    // the items report: 3 items, 6 item-days
    $this->range = ['from' => '2026-11-16', 'to' => '2026-11-17'];
});

it('ships with a limit of 20 000 rows', function () {
    expect(config('analytics.export_max_rows'))->toBe(20000);
});

it('refuses an xlsx with more rows than the limit, before writing or auditing anything', function () {
    config(['analytics.export_max_rows' => 8]);

    $response = $this->d->get('exports/items', $this->range)->assertStatus(422);

    $response->assertJsonPath('message_key', 'analytics.export.too_large')
        ->assertJsonPath('max_rows', 8)
        ->assertJsonPath('rows', 9);
    expect($response->json('message_en'))->toBe((string) trans('analytics.export.too_large', [], 'en'))
        ->and($response->json('message_am'))->toBe((string) trans('analytics.export.too_large', [], 'am'))
        ->and($response->json('message_am'))->toMatch('/\p{Ethiopic}/u')->not->toBe($response->json('message_en'))
        ->and(DB::table('audit_log')->where('action', 'analytics.export')->count())->toBe(0);
});

it('writes a file of exactly the limit', function () {
    config(['analytics.export_max_rows' => 9]);

    $this->d->get('exports/items', $this->range)->assertOk();
    expect(DB::table('audit_log')->where('action', 'analytics.export')->value('after'))->toContain('"rows":9');
});

it('counts only the first table for a CSV and a PDF', function (string $format) {
    config(['analytics.export_max_rows' => 3]);

    $this->d->get('exports/items', $this->range + ['format' => $format])->assertOk();
    config(['analytics.export_max_rows' => 2]);
    $this->d->get('exports/items', $this->range + ['format' => $format])->assertStatus(422)
        ->assertJsonPath('message_key', 'analytics.export.too_large')
        ->assertJsonPath('rows', 3);
})->with(['csv', 'pdf']);

it('counts every day of the range in the sales report, built or not, and the 24 hours', function () {
    config(['analytics.export_max_rows' => 24 + 6]);

    $this->d->get('exports/sales', ['from' => '2026-11-16', 'to' => '2026-11-22'])->assertStatus(422)->assertJsonPath('rows', 31);
    $this->d->get('exports/sales', ['from' => '2026-11-16', 'to' => '2026-11-21'])->assertOk();
});
