<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use App\Modules\Shifts\Models\ShiftInstance;
use App\Shared\Time\DayRows;
use App\Shared\Time\LocalDay;
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
                );
                $lastDate = $date;
                $lastId = (int) $row->id;
            }

            if ($rows->count() < self::CHUNK) {
                return;
            }
        }
    }
}
