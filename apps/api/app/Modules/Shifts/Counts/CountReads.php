<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Counts;

use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;

/**
 * The reads of a shift's counts that several endpoints share: the latest revision of each place's count of one type (what start and
 * close answer with, and what decides whether the shift moves on), and every revision oldest first (GET /shifts/{shift}/counts).
 * Branch-scoped through the model (rule 8); resolved per use.
 */
final class CountReads
{
    /**
     * The newest revision (highest seq) of each place's count of the type, ascending by place.
     *
     * @return list<StockCount>
     */
    public function latest(ShiftInstance $shift, CountType $type): array
    {
        $latest = [];
        foreach (StockCount::query()->where('shift_instance_id', $shift->id)->where('type', $type->value)->orderBy('seq')->orderBy('id')->get() as $row) {
            $latest[$row->location_id] = $row;
        }
        ksort($latest);

        return array_values($latest);
    }

    /**
     * Every revision of every count of the shift, oldest first.
     *
     * @return list<StockCount>
     */
    public function all(ShiftInstance $shift): array
    {
        return array_values(StockCount::query()->where('shift_instance_id', $shift->id)->orderBy('started_at')->orderBy('id')->get()->all());
    }
}
