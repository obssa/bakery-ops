<?php

declare(strict_types=1);

use App\Shared\Live\OverlapCursor;
use Tests\Support\Dashboards;

pest()->group('module:analytics', 'feature');

/*
 * GET /api/v1/analytics/shifts (phase-4 plan section 4.6, step 4.4c; analytics.view). One point a day of the range from
 * daily_station_metrics summed over the stations: shifts that ran that day (a Night shift counts on both days), those of them locked,
 * the variance lines and the cash variance of the shifts dated that day; a day not built has null figures. totals adds up the variance
 * lines and the cash variance (each belongs to one day, the shift's date, so the sum counts nothing twice; shift-days are not summed).
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $d->rows->built('2026-11-16')
        ->station('2026-11-16', $d->kitchen->id, ['shifts_count' => 1, 'shifts_locked_count' => 1, 'variance_line_count' => 2])
        ->station('2026-11-16', $d->till->id, ['shifts_count' => 1, 'shifts_locked_count' => 1, 'variance_line_count' => 1, 'cash_variance_total' => '-5.00'])
        ->station('2026-11-16', $d->bar->id, ['shifts_count' => 1]);
    $d->rows->built('2026-11-17')->station('2026-11-17', $d->kitchen->id, ['shifts_count' => 1]);
    $d->rows->built('2026-11-19');
    $d->rows->built('2026-11-12')->station('2026-11-12', $d->till->id, ['shifts_count' => 1, 'shifts_locked_count' => 1, 'cash_variance_total' => '2.50']);
    $this->range = ['from' => '2026-11-16', 'to' => '2026-11-19'];
});

it('gives each day of the range its shifts, locks and variances over every station, and the totals of the variances', function () {
    $response = $this->d->get('shifts', $this->range)->assertOk();

    expect($response->json('data'))->toBe([
        'days' => [
            ['day' => '2026-11-16', 'built' => true, 'shifts_count' => 3, 'shifts_locked_count' => 2, 'variance_line_count' => 3, 'cash_variance_total' => '-5.00'],
            ['day' => '2026-11-17', 'built' => true, 'shifts_count' => 1, 'shifts_locked_count' => 0, 'variance_line_count' => 0, 'cash_variance_total' => '0.00'],
            ['day' => '2026-11-18', 'built' => false, 'shifts_count' => null, 'shifts_locked_count' => null, 'variance_line_count' => null, 'cash_variance_total' => null],
            ['day' => '2026-11-19', 'built' => true, 'shifts_count' => 0, 'shifts_locked_count' => 0, 'variance_line_count' => 0, 'cash_variance_total' => '0.00'],
        ],
        'totals' => ['variance_line_count' => 3, 'cash_variance_total' => '-5.00'],
    ])->and($response->json('stale_days'))->toBe(['2026-11-18']);
});

it('adds the days of the period before with ?compare=previous', function () {
    $response = $this->d->get('shifts', $this->range + ['compare' => 'previous'])->assertOk();

    expect(array_column($response->json('previous.data.days'), 'day'))->toBe(['2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15'])
        ->and($response->json('previous.data.totals'))->toBe(['variance_line_count' => 0, 'cash_variance_total' => '2.50']);
});

it('answers 304 until a day of the range is rebuilt', function () {
    $tag = (string) $this->d->get('shifts', $this->range)->headers->get('ETag');
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds();

    $this->d->get('shifts', $this->range, 'manager', ['If-None-Match' => $tag])->assertStatus(304);
    $this->d->rows->built('2026-11-18');
    $this->d->get('shifts', $this->range, 'manager', ['If-None-Match' => $tag])->assertOk()->assertJsonPath('stale_days', []);
});

it('refuses a bad range or comparison', function (array $query, string $key, array $extra) {
    $response = $this->d->get('shifts', $query)->assertStatus(422)->assertJsonPath('message_key', $key);
    foreach ($extra as $path => $value) {
        $response->assertJsonPath($path, $value);
    }
})->with(Dashboards::rangeErrors());

it('is for the admin and the manager only', function (string $role, bool $allowed) {
    $response = $this->d->get('shifts', [], $role);

    $allowed ? $response->assertOk() : $response->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
})->with(Dashboards::roles(['admin', 'manager']));
