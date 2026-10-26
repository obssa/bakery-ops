<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\CashDeclaration;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCountLine;
use App\Shared\Time\DayRows;
use App\Shared\Time\LocalDay;
use App\Shared\Values\Money;
use Generator;

/**
 * What Analytics may read of Shifts (phase-4 plan section 2 and 10.5, contract analytics->facts): each shift ONCE, with `date` (its
 * local START date, which is also its `day`), `opened_at`, `closed_at` (when the closing count was submitted), `locked_at`.
 *
 * A shift belongs to every local day between its opening and its lock (a Night shift 22:00 to 06:00 lives on two), so it is found by
 * a range holding any of them, and by a range holding its `date` even when it never opened (scheduled or cancelled). A shift that
 * is still running has no end yet and is found by every range from its opening on. Ordered (date, id), chunked; an empty range
 * yields nothing. Both ends of the range are inclusive local dates in the branch's timezone (LocalDay). Branch-scoped through the
 * model, resolved per use.
 *
 * Each shift also carries what its FINAL counts found (step 4.4a): `variance_line_count`, the lines with a non-zero variance of its
 * counts that are approved or locked and not superseded (a draft, a count waiting for approval, a rejected or a superseded revision
 * is left out), and `cash_variance`, the sum of those counts' cash declaration variances (null when it declared no cash). Two
 * grouped queries per chunk of shifts.
 */
final class Facts
{
    private const int CHUNK = 500;

    /** @return Generator<int, ShiftFact> */
    public function instances(LocalDay $range): Generator
    {
        [$start, $end] = $range->utcBounds();
        $lastDate = '';
        $lastId = 0;

        while (true) {
            $rows = ShiftInstance::query()->toBase()
                ->select('shift_instances.*')
                ->where(function ($q) use ($range, $start, $end): void {
                    $q->whereBetween('shift_instances.date', [$range->from, $range->to])
                        ->orWhere(function ($lived) use ($start, $end): void {
                            $lived->whereNotNull('shift_instances.opened_at')
                                ->where('shift_instances.opened_at', '<', DayRows::sql($end))
                                ->whereRaw('COALESCE(shift_instances.locked_at, shift_instances.cancelled_at, ?) >= ?', ['9999-12-31 00:00:00', DayRows::sql($start)]);
                        });
                })
                ->when($lastId > 0, fn ($q) => $q->where(fn ($w) => $w
                    ->where('shift_instances.date', '>', $lastDate)
                    ->orWhere(fn ($same) => $same->where('shift_instances.date', $lastDate)->where('shift_instances.id', '>', $lastId))))
                ->orderBy('shift_instances.date')
                ->orderBy('shift_instances.id')
                ->limit(self::CHUNK)
                ->get();

            [$varianceLines, $cashVariances] = $this->countFigures(array_values(array_map(static fn ($row): int => (int) $row->id, $rows->all())));

            foreach ($rows as $row) {
                $date = substr((string) $row->date, 0, 10);
                yield new ShiftFact(
                    (int) $row->id,
                    $date,
                    $date,
                    (int) $row->station_id,
                    (string) $row->status,
                    DayRows::iso($row->opened_at),
                    DayRows::iso($row->submitted_at),
                    DayRows::iso($row->locked_at),
                    DayRows::iso($row->cancelled_at),
                    $row->opening_mode === null ? null : (string) $row->opening_mode,
                    $varianceLines[(int) $row->id] ?? 0,
                    $cashVariances[(int) $row->id] ?? null,
                );
                $lastDate = $date;
                $lastId = (int) $row->id;
            }

            if ($rows->count() < self::CHUNK) {
                return;
            }
        }
    }

    /**
     * Per shift id: the number of non-zero variance lines and the cash variance (money, scale 2) of its final counts.
     *
     * @param  list<int>  $shiftIds
     * @return array{0: array<int, int>, 1: array<int, string>}
     */
    private function countFigures(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [[], []];
        }
        $final = [CountStatus::Approved->value, CountStatus::Locked->value];

        $lines = [];
        $rows = StockCountLine::query()->toBase()
            ->join('stock_counts', 'stock_counts.id', '=', 'stock_count_lines.count_id')
            ->whereIn('stock_counts.shift_instance_id', $shiftIds)
            ->whereIn('stock_counts.status', $final)
            ->whereNull('stock_counts.superseded_by_id')
            ->whereNotNull('stock_count_lines.variance_qty')
            ->where('stock_count_lines.variance_qty', '<>', 0)
            ->groupBy('stock_counts.shift_instance_id')
            ->selectRaw('stock_counts.shift_instance_id as shift_id, count(*) as variance_lines')
            ->get();
        foreach ($rows as $row) {
            $lines[(int) $row->shift_id] = (int) $row->variance_lines;
        }

        $cash = [];
        $rows = CashDeclaration::query()->toBase()
            ->join('stock_counts', 'stock_counts.id', '=', 'cash_declarations.count_id')
            ->whereIn('stock_counts.shift_instance_id', $shiftIds)
            ->whereIn('stock_counts.status', $final)
            ->whereNull('stock_counts.superseded_by_id')
            ->whereNotNull('cash_declarations.variance_total')
            ->groupBy('stock_counts.shift_instance_id')
            ->selectRaw('stock_counts.shift_instance_id as shift_id, sum(cash_declarations.variance_total) as variance')
            ->get();
        foreach ($rows as $row) {
            $cash[(int) $row->shift_id] = Money::of((string) $row->variance)->amount();
        }

        return [$lines, $cash];
    }
}
