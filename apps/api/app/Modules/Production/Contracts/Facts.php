<?php

declare(strict_types=1);

namespace App\Modules\Production\Contracts;

use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Shared\Time\DayRows;
use App\Shared\Time\LocalDay;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Generator;
use stdClass;

/**
 * What Analytics may read of Production (phase-4 plan section 2, contract analytics->facts): batches that were finished
 * (`finished` or `confirmed`; a `started` batch has made nothing yet), on the local day of `finished_at`. Reads only. A generator
 * over a chunked query, one local day at a time, ordered (day, id); an empty range yields nothing; both ends are inclusive local
 * dates in the branch's timezone (LocalDay). Branch-scoped through the model, resolved per use.
 */
final class Facts
{
    /** @return Generator<int, BatchFact> */
    public function batches(LocalDay $range): Generator
    {
        return DayRows::generate(
            $range,
            'production_batches.id',
            fn (CarbonImmutable $start, CarbonImmutable $end) => ProductionBatch::query()->toBase()
                ->select('production_batches.*')
                ->whereIn('production_batches.status', [BatchStatus::Finished->value, BatchStatus::Confirmed->value])
                ->where('production_batches.finished_at', '>=', DayRows::sql($start))
                ->where('production_batches.finished_at', '<', DayRows::sql($end)),
            fn (stdClass $row, string $day): BatchFact => new BatchFact(
                (int) $row->id,
                $day,
                (int) $row->station_id,
                (int) $row->recipe_id,
                (string) $row->status,
                Quantity::of((string) $row->planned_qty)->toString(),
                Quantity::of((string) ($row->produced_qty ?? '0'))->toString(),
                Quantity::of((string) ($row->waste_qty ?? '0'))->toString(),
                (string) DayRows::iso($row->started_at),
                (string) DayRows::iso($row->finished_at),
                $row->shift_instance_id === null ? null : (int) $row->shift_instance_id,
            ),
        );
    }
}
