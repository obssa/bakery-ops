<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Stations;
use App\Shared\Enums\StationCode;
use App\Shared\Live\OverlapCursor;
use App\Shared\Models\Station;
use Tests\Support\Dashboards;

pest()->group('module:analytics', 'feature');

/*
 * GET /api/v1/analytics/stations (phase-4 plan section 4.6, step 4.4c). analytics.view (admin, manager) reads every station;
 * analytics.view_own (everyone else) reads only the stations the caller works at (Identity's ActorDirectory: their assignments), so a
 * waiter, who works at none, reads an empty list. daily_station_metrics summed per station over the range, named through ActorDirectory (a
 * removed station too): tickets, lines served and rejected, prep time (the exact mean over the range from the days' prep_samples and
 * prep_seconds_sum; the 90th percentile only for a one-day range, as a percentile of many days cannot be made from the days' percentiles,
 * beside it the worst day's), batches, produced and wasted, shift-days (a Night shift counts on both days it ran), locked shift-days,
 * variance lines, cash variance, stock-outs.
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $this->bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $d->branch->id, 'name_en' => 'Bakery', 'name_am' => 'ዳቦ ቤት']);
    $this->bakery->delete();
    $d->rows->built('2026-11-16')
        ->station('2026-11-16', $d->kitchen->id, ['tickets_count' => 5, 'lines_served' => 6, 'lines_rejected' => 1, 'prep_samples' => 4, 'prep_seconds_sum' => 2400,
            'avg_prep_seconds' => 600, 'p90_prep_seconds' => 900, 'shifts_count' => 1, 'shifts_locked_count' => 1, 'variance_line_count' => 2, 'stockout_count' => 1])
        ->station('2026-11-16', $d->bar->id, ['tickets_count' => 2, 'lines_served' => 2, 'prep_samples' => 2, 'prep_seconds_sum' => 360, 'avg_prep_seconds' => 180,
            'p90_prep_seconds' => 200, 'shifts_count' => 1])
        ->station('2026-11-16', $this->bakery->id, ['batches_finished' => 2, 'produced_qty' => '24.0000', 'waste_qty' => '1.0000']);
    $d->rows->built('2026-11-17')
        ->station('2026-11-17', $d->kitchen->id, ['tickets_count' => 3, 'lines_served' => 3, 'prep_samples' => 2, 'prep_seconds_sum' => 700, 'avg_prep_seconds' => 350,
            'p90_prep_seconds' => 400, 'shifts_count' => 1])
        ->station('2026-11-17', $d->till->id, ['shifts_count' => 1, 'shifts_locked_count' => 1, 'cash_variance_total' => '-5.00']);
    $this->week = ['from' => '2026-11-16', 'to' => '2026-11-22'];
    $this->label = fn (Station $s, bool $active = true): array => ['id' => $s->id, 'code' => $s->code->value, 'name_en' => $s->name_en, 'name_am' => $s->name_am, 'is_active' => $active];
    $this->ids = fn (string $as, array $query = []): array => array_column(array_column($this->d->get('stations', ($query ?: $this->week), $as)->assertOk()->json('data.stations'), 'station'), 'id');
});

it('sums each station over the range, with the exact mean prep time and the worst day\'s 90th percentile', function () {
    $d = $this->d;
    $nothing = ['tickets_count' => 0, 'lines_served' => 0, 'lines_rejected' => 0, 'prep_samples' => 0, 'avg_prep_seconds' => null, 'p90_prep_seconds' => null,
        'worst_day_p90_prep_seconds' => null, 'batches_finished' => 0, 'produced_qty' => '0.0000', 'waste_qty' => '0.0000', 'shifts_count' => 0,
        'shifts_locked_count' => 0, 'variance_line_count' => 0, 'cash_variance_total' => '0.00', 'stockout_count' => 0];

    expect($d->get('stations', $this->week)->assertOk()->json('data.stations'))->toBe([
        ['station' => ($this->label)($d->kitchen)] + array_merge($nothing, ['tickets_count' => 8, 'lines_served' => 9, 'lines_rejected' => 1, 'prep_samples' => 6,
            'avg_prep_seconds' => 517, 'worst_day_p90_prep_seconds' => 900, 'shifts_count' => 2, 'shifts_locked_count' => 1, 'variance_line_count' => 2, 'stockout_count' => 1]),
        ['station' => ($this->label)($d->bar)] + array_merge($nothing, ['tickets_count' => 2, 'lines_served' => 2, 'prep_samples' => 2, 'avg_prep_seconds' => 180,
            'worst_day_p90_prep_seconds' => 200, 'shifts_count' => 1]),
        ['station' => ($this->label)($d->till)] + array_merge($nothing, ['shifts_count' => 1, 'shifts_locked_count' => 1, 'cash_variance_total' => '-5.00']),
        ['station' => ($this->label)($this->bakery, false)] + array_merge($nothing, ['batches_finished' => 2, 'produced_qty' => '24.0000', 'waste_qty' => '1.0000']),
    ]);
});

it('gives the 90th percentile of a one-day range', function () {
    $kitchen = $this->d->get('stations', ['from' => '2026-11-16', 'to' => '2026-11-16'])->assertOk()->json('data.stations.0');

    expect($kitchen)->toMatchArray(['prep_samples' => 4, 'avg_prep_seconds' => 600, 'p90_prep_seconds' => 900, 'worst_day_p90_prep_seconds' => 900]);
});

it('limits analytics.view_own to the stations the caller works at', function () {
    $d = $this->d;

    expect(($this->ids)('admin'))->toBe([$d->kitchen->id, $d->bar->id, $d->till->id, $this->bakery->id])
        ->and(($this->ids)('manager'))->toHaveCount(4)
        ->and(($this->ids)('chef'))->toBe([$d->kitchen->id])
        ->and(($this->ids)('barista'))->toBe([$d->bar->id])
        ->and(($this->ids)('cashier'))->toBe([$d->till->id])
        ->and(($this->ids)('waiter'))->toBe([])
        ->and(($this->ids)('cake_baker'))->toBe([]);
});

it('adds the stations of the week before with ?compare=previous', function () {
    $this->d->rows->built('2026-11-12')->station('2026-11-12', $this->d->bar->id, ['lines_served' => 4]);

    $response = $this->d->get('stations', $this->week + ['compare' => 'previous'], 'barista')->assertOk();
    expect($response->json('previous.data.stations'))->toHaveCount(1)
        ->and($response->json('previous.data.stations.0.lines_served'))->toBe(4);
});

it('answers 304 per caller until a day of the range is rebuilt or the caller\'s stations change', function () {
    $d = $this->d;
    $chef = (string) $d->get('stations', $this->week, 'chef')->headers->get('ETag');
    $admin = (string) $d->get('stations', $this->week, 'admin')->headers->get('ETag');
    expect($chef)->not->toBe($admin);
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds();

    $d->get('stations', $this->week, 'chef', ['If-None-Match' => $chef])->assertStatus(304);
    $d->get('stations', $this->week, 'chef', ['If-None-Match' => $admin])->assertOk();
    app(Stations::class)->replace($d->users['chef'], [
        ['station_id' => $d->kitchen->id, 'can_count' => true, 'is_overseer' => false],
        ['station_id' => $d->bar->id, 'can_count' => true, 'is_overseer' => false],
    ]);
    $d->get('stations', $this->week, 'chef', ['If-None-Match' => $chef])->assertOk()->assertJsonCount(2, 'data.stations');
    $d->get('stations', $this->week, 'admin', ['If-None-Match' => $admin])->assertStatus(304);
    $d->rows->rebuilt('2026-11-17', '2026-11-22 08:00:00');
    $d->get('stations', $this->week, 'admin', ['If-None-Match' => $admin])->assertOk();
});

it('refuses a bad range or comparison', function (array $query, string $key, array $extra) {
    $response = $this->d->get('stations', $query, 'chef')->assertStatus(422)->assertJsonPath('message_key', $key);
    foreach ($extra as $path => $value) {
        $response->assertJsonPath($path, $value);
    }
})->with(Dashboards::rangeErrors());

it('is open to every role, each reading what it may', function (string $role, bool $allowed) {
    expect($allowed)->toBeTrue();
    $this->d->get('stations', [], $role)->assertOk();
})->with(Dashboards::roles(Dashboards::ROLES));
