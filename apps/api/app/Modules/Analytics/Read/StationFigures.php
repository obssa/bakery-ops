<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use stdClass;

/**
 * A station's figures as GET /analytics/stations gives them (step 4.4c) and the stations export prints them (step 4.4d), from
 * daily_station_metrics summed over a period or from one day's row. avg_prep_seconds is exact over the days: their prep_seconds_sum over
 * their prep_samples, half-up. A 90th percentile of many days cannot be made from the days' percentiles, so p90_prep_seconds is given for a
 * one-day period only, and worst_day_p90_prep_seconds (the highest of the days') beside it. Pure.
 */
final class StationFigures
{
    /**
     * @param  array<string, mixed>  $station  the station's name (Names::stations)
     * @param  stdClass  $row  the sums of a period (with worst_day_p90_prep_seconds) or one day's row (with p90_prep_seconds)
     * @return array<string, mixed>
     */
    public static function of(array $station, stdClass $row, bool $oneDay): array
    {
        $samples = Sum::int($row->prep_samples);
        $p90 = property_exists($row, 'worst_day_p90_prep_seconds') ? $row->worst_day_p90_prep_seconds : ($row->p90_prep_seconds ?? null);
        $worst = $p90 === null ? null : Sum::int($p90);

        return [
            'station' => $station,
            'tickets_count' => Sum::int($row->tickets_count),
            'lines_served' => Sum::int($row->lines_served),
            'lines_rejected' => Sum::int($row->lines_rejected),
            'prep_samples' => $samples,
            'avg_prep_seconds' => Sum::meanSeconds(Sum::int($row->prep_seconds_sum), $samples),
            'p90_prep_seconds' => $oneDay ? $worst : null,
            'worst_day_p90_prep_seconds' => $worst,
            'batches_finished' => Sum::int($row->batches_finished),
            'produced_qty' => Sum::qty($row->produced_qty),
            'waste_qty' => Sum::qty($row->waste_qty),
            'shifts_count' => Sum::int($row->shifts_count),
            'shifts_locked_count' => Sum::int($row->shifts_locked_count),
            'variance_line_count' => Sum::int($row->variance_line_count),
            'cash_variance_total' => Sum::money($row->cash_variance_total),
            'stockout_count' => Sum::int($row->stockout_count),
        ];
    }
}
