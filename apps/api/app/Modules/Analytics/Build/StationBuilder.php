<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Build;

use App\Modules\Inventory\Contracts\StockOutFact;
use App\Modules\Orders\Contracts\StationLineFact;
use App\Modules\Production\Contracts\BatchFact;
use App\Modules\Shifts\Contracts\ShiftFact;
use Brick\Math\BigDecimal;

/**
 * daily_station_metrics (phase-4 plan M16; ADR 0014; 10.5). Per station, each figure on its own clock in the branch's local days:
 *   - the station lines (Orders\Facts::stationLines gives a line once per day it touched): tickets = the orders whose lines were
 *     readied or rejected that day; lines_served by served_at; lines_rejected by rejected_at; prep = accepted_at to ready_at of the
 *     lines readied that day (mean and nearest-rank 90th percentile, whole seconds; null with no sample), and (step 4.4c) how many
 *     samples there were and their seconds added, so the dashboards' mean over a range is exact;
 *   - batches by finished_at (the port's day);
 *   - shifts: a shift that opened counts on every local day from its opening to its lock (else its cancellation; a running shift has no
 *     end yet), so a Night shift counts on two days; one that never opened is not counted. Its variance lines and cash variance belong
 *     to its `date` only, so nothing is counted twice;
 *   - stock-outs begun that day at a place of the station.
 * A station with nothing that day has no row; rows ascend by station id. Pure.
 */
final class StationBuilder
{
    /**
     * @param  list<StationLineFact>  $lines
     * @param  list<BatchFact>  $batches
     * @param  list<ShiftFact>  $shifts
     * @param  list<StockOutFact>  $stockOuts
     * @return list<array<string, mixed>>
     */
    public function build(string $day, string $timezone, array $lines, array $batches, array $shifts, array $stockOuts): array
    {
        $s = [];
        $blank = static fn (): array => [
            'tickets' => [], 'served' => 0, 'rejected' => 0, 'prep' => [], 'batches' => 0, 'produced' => BigDecimal::zero(), 'waste' => BigDecimal::zero(),
            'shifts' => 0, 'locked' => 0, 'variance' => 0, 'cash' => BigDecimal::zero(), 'stockouts' => 0,
        ];

        foreach ($lines as $line) {
            $c = $s[$line->stationId] ?? $blank();
            $readyToday = Figures::dayOf($line->readyAt, $timezone) === $day;
            $rejectedToday = Figures::dayOf($line->rejectedAt, $timezone) === $day;
            if ($readyToday || $rejectedToday) {
                $c['tickets'][$line->orderId] = true;
            }
            if ($readyToday && $line->acceptedAt !== null && $line->readyAt !== null) {
                $c['prep'][] = Figures::seconds($line->acceptedAt, $line->readyAt);
            }
            $c['served'] += Figures::dayOf($line->servedAt, $timezone) === $day ? 1 : 0;
            $c['rejected'] += $rejectedToday ? 1 : 0;
            $s[$line->stationId] = $c;
        }
        foreach ($batches as $batch) {
            $c = $s[$batch->stationId] ?? $blank();
            $c['batches']++;
            $c['produced'] = $c['produced']->plus($batch->producedQty);
            $c['waste'] = $c['waste']->plus($batch->wasteQty);
            $s[$batch->stationId] = $c;
        }
        foreach ($shifts as $shift) {
            $alive = self::aliveOn($shift, $day, $timezone);
            $dated = $shift->date === $day;
            if (! $alive && ! $dated) {
                continue;
            }
            $c = $s[$shift->stationId] ?? $blank();
            if ($alive) {
                $c['shifts']++;
                $c['locked'] += $shift->status === 'locked' ? 1 : 0;
            }
            if ($dated) {
                $c['variance'] += $shift->varianceLineCount;
                $c['cash'] = $c['cash']->plus($shift->cashVariance ?? '0');
            }
            $s[$shift->stationId] = $c;
        }
        foreach ($stockOuts as $out) {
            if ($out->stationId !== null) {
                $c = $s[$out->stationId] ?? $blank();
                $c['stockouts']++;
                $s[$out->stationId] = $c;
            }
        }
        ksort($s);

        $rows = [];
        foreach ($s as $stationId => $c) {
            $nothing = $c['tickets'] === [] && $c['served'] === 0 && $c['rejected'] === 0 && $c['batches'] === 0 && $c['shifts'] === 0
                && $c['variance'] === 0 && $c['cash']->isZero() && $c['stockouts'] === 0;
            if ($nothing) {
                continue;
            }
            $rows[] = [
                'day' => $day,
                'station_id' => $stationId,
                'tickets_count' => count($c['tickets']),
                'lines_served' => $c['served'],
                'lines_rejected' => $c['rejected'],
                'avg_prep_seconds' => Figures::mean($c['prep']),
                'p90_prep_seconds' => Figures::percentile($c['prep'], 90),
                'prep_samples' => count($c['prep']),
                'prep_seconds_sum' => array_sum($c['prep']),
                'batches_finished' => $c['batches'],
                'produced_qty' => Figures::qty($c['produced']),
                'waste_qty' => Figures::qty($c['waste']),
                'shifts_count' => $c['shifts'],
                'shifts_locked_count' => $c['locked'],
                'variance_line_count' => $c['variance'],
                'cash_variance_total' => Figures::money($c['cash']),
                'stockout_count' => $c['stockouts'],
            ];
        }

        return $rows;
    }

    /** Did the shift live on $day: opened on or before it, and not ended (locked, else cancelled) before it? */
    private static function aliveOn(ShiftFact $shift, string $day, string $timezone): bool
    {
        $opened = Figures::dayOf($shift->openedAt, $timezone);
        if ($opened === null || $opened > $day) {
            return false;
        }
        $ended = Figures::dayOf($shift->lockedAt ?? $shift->cancelledAt, $timezone);

        return $ended === null || $ended >= $day;
    }
}
