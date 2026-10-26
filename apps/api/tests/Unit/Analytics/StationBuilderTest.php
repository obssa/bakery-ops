<?php

declare(strict_types=1);

use App\Modules\Analytics\Build\StationBuilder;
use Tests\Support\AnalyticsFacts as F;

/*
 * daily_station_metrics (phase-4 plan M16; ADR 0014; 10.5). Each figure on its own clock, in the branch's local days:
 *   - tickets: the orders whose lines the station readied or rejected that day; lines_served by served_at; lines_rejected by
 *     rejected_at; prep seconds (accepted_at to ready_at) of the lines readied that day: the mean and the 90th percentile (nearest
 *     rank), whole seconds, half-up; null when no line was readied from an accepted state;
 *   - production by the batch's finished_at;
 *   - shifts: every local day between opening and lock (a running shift up to today; a shift that never opened is not counted);
 *     the variance lines and the cash variance belong to the shift's `date` only, so a Night shift is not counted twice;
 *   - stock-outs that began that day at a place of the station.
 * The day is 2026-11-20 in Africa/Addis_Ababa (UTC+3): 20:59:59Z is 23:59:59 that day, 21:00:00Z already the 21st.
 */

it('builds every station figure of a day against a hand-made oracle', function () {
    $D = F::DAY;
    $rows = (new StationBuilder)->build(
        $D,
        F::TZ,
        [
            // kitchen (1): three readied lines (600 s, 120 s, 1199 s), one rejected, one readied yesterday and served today
            F::stationLine(['line_id' => 1, 'order_id' => 100, 'station_id' => 1, 'accepted_at' => "{$D}T06:50:00Z", 'ready_at' => "{$D}T07:00:00Z", 'served_at' => "{$D}T07:05:00Z"]),
            F::stationLine(['line_id' => 2, 'order_id' => 100, 'station_id' => 1, 'accepted_at' => "{$D}T06:58:00Z", 'ready_at' => "{$D}T07:00:00Z"]),
            F::stationLine(['line_id' => 3, 'order_id' => 101, 'station_id' => 1, 'accepted_at' => "{$D}T20:40:00Z", 'ready_at' => "{$D}T20:59:59Z", 'served_at' => "{$D}T21:00:00Z"]),
            F::stationLine(['line_id' => 4, 'order_id' => 102, 'station_id' => 1, 'status' => 'rejected', 'rejected_at' => "{$D}T08:00:00Z"]),
            F::stationLine(['line_id' => 5, 'order_id' => 103, 'station_id' => 1, 'accepted_at' => '2026-11-19T20:00:00Z', 'ready_at' => '2026-11-19T20:30:00Z', 'served_at' => '2026-11-19T21:10:00Z']),
            // bar (2): readied straight from the queue (no accepted_at: no prep sample), served
            F::stationLine(['line_id' => 6, 'order_id' => 100, 'station_id' => 2, 'ready_at' => "{$D}T09:00:00Z", 'served_at' => "{$D}T09:01:00Z"]),
        ],
        [
            F::batch(['batch_id' => 1, 'station_id' => 3, 'produced_qty' => '24', 'waste_qty' => '1']),
            F::batch(['batch_id' => 2, 'station_id' => 3, 'produced_qty' => '12']),
        ],
        [
            F::shift(['shift_id' => 1, 'station_id' => 1, 'variance_line_count' => 2]),
            F::shift(['shift_id' => 2, 'station_id' => 4, 'status' => 'open', 'locked_at' => null]),
            // bakery Night shift of the 19th, 22:00 to 06:30 the next morning: counted on the 20th, its variance stays on the 19th
            F::shift(['shift_id' => 3, 'station_id' => 3, 'date' => '2026-11-19', 'opened_at' => '2026-11-19T19:00:00Z', 'locked_at' => "{$D}T03:30:00Z", 'variance_line_count' => 1]),
            F::shift(['shift_id' => 4, 'station_id' => 3, 'opened_at' => "{$D}T04:00:00Z", 'locked_at' => "{$D}T14:00:00Z", 'variance_line_count' => 3]),
            F::shift(['shift_id' => 5, 'station_id' => 4, 'status' => 'cancelled', 'opened_at' => null, 'locked_at' => null, 'cancelled_at' => "{$D}T02:00:00Z"]),
            F::shift(['shift_id' => 6, 'station_id' => 4, 'opened_at' => "{$D}T15:00:00Z", 'locked_at' => "{$D}T18:00:00Z", 'cash_variance' => '-5.00']),
            // a bar shift of the 18th that waited for approval until the 21st: alive on the 20th
            F::shift(['shift_id' => 8, 'station_id' => 2, 'date' => '2026-11-18', 'opened_at' => '2026-11-18T03:00:00Z', 'locked_at' => '2026-11-21T05:00:00Z', 'variance_line_count' => 4]),
        ],
        [F::stockOut(1, 50, 1), F::stockOut(2, 51, 1), F::stockOut(3, 52, null)],
    );

    $row = fn (int $station, array $figures): array => array_merge([
        'day' => $D, 'station_id' => $station, 'tickets_count' => 0, 'lines_served' => 0, 'lines_rejected' => 0, 'avg_prep_seconds' => null,
        'p90_prep_seconds' => null, 'prep_samples' => 0, 'prep_seconds_sum' => 0, 'batches_finished' => 0, 'produced_qty' => '0.0000', 'waste_qty' => '0.0000', 'shifts_count' => 0,
        'shifts_locked_count' => 0, 'variance_line_count' => 0, 'cash_variance_total' => '0.00', 'stockout_count' => 0,
    ], $figures);

    expect($rows)->toBe([
        $row(1, ['tickets_count' => 3, 'lines_served' => 2, 'lines_rejected' => 1, 'avg_prep_seconds' => 640, 'p90_prep_seconds' => 1199, 'prep_samples' => 3, 'prep_seconds_sum' => 1919,
            'shifts_count' => 1, 'shifts_locked_count' => 1, 'variance_line_count' => 2, 'stockout_count' => 2]),
        $row(2, ['tickets_count' => 1, 'lines_served' => 1, 'shifts_count' => 1, 'shifts_locked_count' => 1]),
        $row(3, ['batches_finished' => 2, 'produced_qty' => '36.0000', 'waste_qty' => '1.0000', 'shifts_count' => 2, 'shifts_locked_count' => 2, 'variance_line_count' => 3]),
        $row(4, ['shifts_count' => 2, 'shifts_locked_count' => 1, 'cash_variance_total' => '-5.00']),
    ]);
})->group('unit', 'module:analytics');

it('takes the 90th percentile by nearest rank and rounds the mean half-up to a whole second', function () {
    $lines = [];
    foreach (range(1, 10) as $i) {
        $lines[] = F::stationLine(['line_id' => $i, 'order_id' => $i, 'accepted_at' => F::DAY.'T06:00:00Z', 'ready_at' => sprintf('%sT06:%02d:00Z', F::DAY, $i)]);
    }
    $lines[] = F::stationLine(['line_id' => 11, 'order_id' => 11, 'accepted_at' => F::DAY.'T06:00:00Z', 'ready_at' => F::DAY.'T06:00:01Z']);

    $row = (new StationBuilder)->build(F::DAY, F::TZ, $lines, [], [], [])[0];

    // 60, 120, ... 600 and 1 s: the sum is 3301 over 11 lines = 300.09 -> 300; rank ceil(0.9 x 11) = 10 -> 540
    expect($row['avg_prep_seconds'])->toBe(300)->and($row['p90_prep_seconds'])->toBe(540)->and($row['tickets_count'])->toBe(11)
        // step 4.4c: the samples and their sum, so a range's mean is exact (GET /analytics/stations)
        ->and($row['prep_samples'])->toBe(11)->and($row['prep_seconds_sum'])->toBe(3301);
})->group('unit', 'module:analytics');

it('counts a running shift on every day from its opening, and nothing for a day before it opened', function () {
    $running = [F::shift(['shift_id' => 1, 'station_id' => 1, 'status' => 'open', 'date' => '2026-11-19', 'opened_at' => '2026-11-19T03:00:00Z', 'locked_at' => null])];

    expect((new StationBuilder)->build(F::DAY, F::TZ, [], [], $running, [])[0]['shifts_count'])->toBe(1)
        ->and((new StationBuilder)->build('2026-11-18', F::TZ, [], [], $running, []))->toBe([]);
})->group('unit', 'module:analytics');
