<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Shared\Events\CountSubmitted;
use App\Shared\Events\VarianceDecided;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;

/**
 * Builds CountSubmitted and VarianceDecided from a count that just changed state and dispatches them (phase-3 plan section 5). Called
 * inside the action's transaction: both events are ShouldDispatchAfterCommit, so they leave only when the outermost transaction
 * commits and never when it rolls back. Names are read through the Inventory and Catalog contracts, in both languages, so
 * Notifications can write its text from the payload alone. Resolved per use.
 */
final class CountAnnouncer
{
    /** At most this many variance items travel in the event (the notification names them; the screen has the rest). */
    public const int MAX_ITEMS = 5;

    public function __construct(
        private readonly Items $items,
        private readonly Locations $locations,
    ) {}

    /** The count was handed in (or co-signed): it now waits at `$count->status`. */
    public function submitted(StockCount $count, ?int $cosignedBy = null): void
    {
        [$station, $place] = $this->where($count);
        $lines = StockCountLine::query()->where('count_id', $count->id)->orderBy('item_id')->get();
        $varying = [];
        foreach ($lines as $line) {
            if ($line->variance_qty instanceof Quantity && ! $line->variance_qty->isZero()) {
                $varying[] = $line;
            }
        }
        usort($varying, static function (StockCountLine $a, StockCountLine $b): int {
            $byBiggest = ($b->variance_qty ?? Quantity::zero())->abs()->compareTo(($a->variance_qty ?? Quantity::zero())->abs());

            return $byBiggest !== 0 ? $byBiggest : $a->item_id <=> $b->item_id;
        });
        $top = array_slice($varying, 0, self::MAX_ITEMS);
        $summaries = $this->items->summaries(array_map(static fn (StockCountLine $l): int => $l->item_id, $top));

        $items = [];
        foreach ($top as $line) {
            $summary = $summaries[$line->item_id] ?? null;
            $items[] = [
                'item_name_en' => $summary->nameEn ?? '',
                'item_name_am' => $summary->nameAm ?? '',
                'expected_qty' => ($line->expected_qty ?? Quantity::zero())->toString(),
                'counted_qty' => ($line->counted_qty ?? Quantity::zero())->toString(),
                'variance_qty' => ($line->variance_qty ?? Quantity::zero())->toString(),
                'unit' => $summary->unit ?? '',
            ];
        }

        event(new CountSubmitted(
            $count->branch_id,
            $count->id,
            $count->shift_instance_id,
            $station->id,
            $station->code->value,
            $station->name_en,
            $station->name_am,
            $count->location_id,
            $place['en'],
            $place['am'],
            $count->type->value,
            $count->seq,
            $count->status->value,
            count($lines),
            count($varying),
            $items,
            null,
            (int) $count->counted_by_user_id,
            $cosignedBy,
        ));
    }

    /** The count was approved or rejected by $decidedBy; a rejection names its recount. */
    public function decided(StockCount $count, int $adjustedLines, ?StockCount $recount = null): void
    {
        [$station, $place] = $this->where($count);

        event(new VarianceDecided(
            $count->branch_id,
            $count->id,
            $count->shift_instance_id,
            $station->id,
            $station->code->value,
            $station->name_en,
            $station->name_am,
            $count->location_id,
            $place['en'],
            $place['am'],
            $count->type->value,
            (string) $count->decision?->value,
            (int) $count->decided_by_user_id,
            $count->decision_note,
            (int) $count->counted_by_user_id,
            $adjustedLines,
            $recount?->id,
        ));
    }

    /**
     * @return array{0: Station, 1: array{en: string, am: string}}
     */
    private function where(StockCount $count): array
    {
        $shift = ShiftInstance::query()->findOrFail($count->shift_instance_id);
        $station = Station::query()->withTrashed()->findOrFail($shift->station_id);
        $view = $this->locations->views([$count->location_id])[$count->location_id] ?? null;

        return [$station, ['en' => $view->nameEn ?? '', 'am' => $view->nameAm ?? '']];
    }
}
